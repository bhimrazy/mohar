<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Orders\PaymentGuard;
use App\Data\Billing\FormPost;
use App\Data\Billing\PaymentIntent;
use App\Data\Billing\QrPayload;
use App\Data\Billing\RedirectInstruction;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Services\CurrencyConverter;
use App\Modules\Billing\Services\GatewayCharger;
use App\Modules\Billing\Services\GatewayLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Begin a hosted payment: store an attempt with our reference and the exact amount the provider must collect,
 * then ask the driver how to send the customer there. Nothing is ever marked paid here.
 */
class StartHostedPayment
{
    public function __construct(
        private GatewayManager $gateways,
        private GatewayCharger $charger,
        private CurrencyConverter $converter,
        private GatewayLogger $log,
        private PaymentGuard $guard,
    ) {}

    /**
     * @return array{0: PaymentAttempt, 1: RedirectInstruction|FormPost|QrPayload}
     */
    public function handle(Invoice $invoice, string $gatewayKey): array
    {
        $gateway = $this->charger->activeGateway($gatewayKey);
        $driver = $this->gateways->hosted($gatewayKey) ?? throw new BillingException(__('This payment method is not available.'));
        $config = $this->gateways->config($gateway);

        if (! $invoice->status->isPayable() || $invoice->balance <= 0) {
            throw new BillingException(__('This invoice has nothing to pay.'));
        }

        if (($reason = $this->guard->refusalReason($invoice->id)) !== null) {
            throw new BillingException($reason);
        }

        $balance = $invoice->balanceMoney();
        $rate = null;

        if (in_array($balance->currency, $driver->currencies(), true)) {
            $amount = $balance;
        } elseif ($gateway->convert_to_currency !== null && in_array(strtoupper($gateway->convert_to_currency), $driver->currencies(), true)) {
            $rate = $this->converter->rate($balance->currency, $gateway->convert_to_currency);
            $amount = Money::of($this->converter->convert($balance->amount, $rate), $gateway->convert_to_currency);
        } else {
            throw new BillingException(__(':gateway can\'t take payments in :currency.', ['gateway' => $gateway->display_name, 'currency' => $balance->currency]));
        }

        $limits = $driver->limits($config);

        if (! $limits->allows($amount->amount)) {
            throw new BillingException(__(':gateway accepts payments between :min and :max.', [
                'gateway' => $gateway->display_name,
                'min' => Money::of($limits->min, $amount->currency)->format(),
                'max' => $limits->max !== null ? Money::of($limits->max, $amount->currency)->format() : '∞',
            ]));
        }

        $attempt = DB::transaction(function () use ($invoice, $gatewayKey, $amount, $balance, $rate, $driver, $config): PaymentAttempt {
            Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            return PaymentAttempt::query()->create([
                'reference' => PaymentAttempt::newReference(),
                'gateway' => $gatewayKey,
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                'amount' => $amount->amount,
                'currency' => $amount->currency,
                'document_amount' => $balance->amount,
                'document_currency' => $balance->currency,
                'fx_rate' => $rate,
                'status' => AttemptStatus::Pending,
                'expires_at' => now()->add($driver->attemptTtl($config)),
            ]);
        });

        $snapshot = $invoice->client_snapshot ?? [];

        try {
            $instruction = $driver->initiate($config, new PaymentIntent(
                reference: $attempt->reference,
                amount: $amount,
                description: __('Invoice :number', ['number' => $invoice->displayNumber()]),
                returnUrl: route('billing.gateways.return', $gatewayKey),
                cancelUrl: route('billing.gateways.return', [$gatewayKey, 'ref' => $attempt->reference, 'cancelled' => 1]),
                customerName: is_string($snapshot['name'] ?? null) ? $snapshot['name'] : null,
                customerEmail: is_string($snapshot['email'] ?? null) ? $snapshot['email'] : null,
                invoiceId: $invoice->id,
            ));
        } catch (Throwable $exception) {
            // Nothing was handed to the customer, so the attempt can never be paid: close it and say so plainly.
            $attempt->update(['status' => AttemptStatus::Failed, 'review_reason' => 'Provider refused to start the payment']);
            $this->log->log($gatewayKey, 'response', 'hosted payment start failed', ['reference' => $attempt->reference, 'error' => mb_substr($exception->getMessage(), 0, 500)], $invoice->id);
            report($exception);

            throw new BillingException(__(':gateway couldn\'t start the payment. Try again in a few minutes or use another method.', ['gateway' => $gateway->display_name]));
        }

        $attempt->update(['provider_ref' => $instruction->providerRef]);
        $this->log->log($gatewayKey, 'request', 'hosted payment started', ['reference' => $attempt->reference, 'amount' => $amount->amount, 'currency' => $amount->currency], $invoice->id);

        return [$attempt, $instruction];
    }
}
