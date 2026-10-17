<?php

namespace App\Modules\Billing\Actions;

use App\Events\Billing\PaymentFailed;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Drivers\Gateways\Data\ChargeResult;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Events\PaymentPending;
use App\Modules\Billing\Events\PayMethodDisabled;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Services\GatewayCharger;
use App\Modules\Billing\Support\BillingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Charge the invoice balance to a stored pay method or a one-off gateway token (§6, §7.2).
 * Success applies the payment; pending → payment_pending; a decline counts against the pay method.
 */
class CaptureInvoice
{
    public function __construct(
        private GatewayCharger $charger,
        private ApplyPayment $applyPayment,
        private BillingSettings $settings,
    ) {}

    public function handle(Invoice $invoice, string $gatewayKey, string $token, ?PayMethod $payMethod = null, ?int $userId = null): ChargeResult
    {
        $lock = Cache::lock('billing.capture.'.$invoice->id, 60);

        if (! $lock->get()) {
            throw new BillingException(__('A payment for this invoice is already in progress.'));
        }

        try {
            return $this->capture($invoice, $gatewayKey, $token, $payMethod, $userId);
        } finally {
            $lock->release();
        }
    }

    private function capture(Invoice $invoice, string $gatewayKey, string $token, ?PayMethod $payMethod, ?int $userId): ChargeResult
    {
        $gateway = $this->charger->activeGateway($gatewayKey);

        $invoice = DB::transaction(function () use ($invoice): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Collections], true) || $invoice->balance <= 0) {
                throw new BillingException(__('This invoice has nothing to pay.'));
            }

            $invoice->last_capture_attempt_at = now();
            $invoice->capture_attempts++;
            $invoice->save();

            return $invoice;
        });

        $balance = $invoice->balanceMoney();
        [$charge, $rate] = $this->charger->chargeAmount($gateway, $balance);
        $result = $this->charger->charge($gateway, $token, $charge, $invoice->displayNumber(), $invoice->client_id, $invoice->id);

        if ($result->succeeded()) {
            $this->applyPayment->handle($invoice, new PaymentData(
                amount: $balance->amount,
                gateway: $gateway->key,
                gatewayTxnId: $result->txnId,
                fee: $result->fee,
                payMethodId: $payMethod?->id,
                raw: $result->raw,
                createdBy: $userId,
                gatewayAmount: $rate !== null ? $charge->amount : null,
                gatewayCurrency: $rate !== null ? $charge->currency : null,
                exchangeRate: $rate,
            ));
            $payMethod?->forceFill(['failure_count' => 0])->save();

            return $result;
        }

        if ($result->isPending()) {
            $invoice->update(['status' => InvoiceStatus::PaymentPending, 'payment_gateway' => $gateway->key]);
            PaymentPending::dispatch($invoice->id, $invoice->client_id, $gateway->key);

            return $result;
        }

        $this->recordFailure($invoice, $payMethod);
        PaymentFailed::dispatch($invoice->id, $invoice->client_id, $gateway->key, $result->declineReason ?? $result->status);

        return $result;
    }

    private function recordFailure(Invoice $invoice, ?PayMethod $payMethod): void
    {
        if ($invoice->first_capture_failure_on === null) {
            $invoice->first_capture_failure_on = CarbonImmutable::today();
            $invoice->save();
        }

        if ($payMethod === null) {
            return;
        }

        $payMethod->failure_count++;

        if ($payMethod->failure_count >= max(1, $this->settings->int('capture.max_failures_per_pay_method', 3)) && $payMethod->disabled_at === null) {
            $payMethod->disabled_at = now();
            PayMethodDisabled::dispatch($payMethod->id, $payMethod->client_id);
        }

        $payMethod->save();
    }
}
