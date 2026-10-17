<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\GatewayDriver;
use App\Data\Billing\CallbackEvent;
use App\Data\Billing\CallbackRejection;
use App\Events\Billing\PaymentFailed;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Actions\RecordChargeback;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use Illuminate\Http\Request;

/**
 * Authoritative gateway callbacks (§6): verify signature → resolve invoice → idempotency → amount/currency
 * check → record → gateway_log. Return URLs are never trusted to mark anything paid.
 *
 * @phpstan-type Outcome array{status: int, result: string}
 */
class GatewayCallbackHandler
{
    public function __construct(
        private GatewayManager $gateways,
        private GatewayLogger $log,
        private CurrencyConverter $converter,
        private ApplyPayment $applyPayment,
        private RefundTransaction $refund,
        private RecordChargeback $chargeback,
    ) {}

    /**
     * @return Outcome HTTP status for the provider (4xx/5xx makes it retry) and the logged result
     */
    public function handle(string $gatewayKey, Request $request): array
    {
        $payload = $request->json()->all();

        if (! $this->gateways->has($gatewayKey) || ! $this->gateways->isActive($gatewayKey)) {
            return $this->outcome($gatewayKey, 404, 'gateway not active', $payload);
        }

        $gateway = $this->gateways->gateway($gatewayKey);

        $driver = $this->gateways->driver($gatewayKey);

        if (! $driver->supports(GatewayDriver::WEBHOOK)) {
            return $this->outcome($gatewayKey, 404, 'callbacks not supported', $payload);
        }

        $event = $driver->parseCallback($this->gateways->config($gateway), $request);

        if ($event instanceof CallbackRejection) {
            return $this->outcome($gatewayKey, $event->isSignatureFailure() ? 403 : 422, $event->reason, $payload);
        }

        return $this->process($gatewayKey, $event);
    }

    /**
     * Apply an already verified event (webhook, or a driver's server-side return confirmation).
     *
     * @return Outcome
     */
    public function process(string $gatewayKey, CallbackEvent $event): array
    {
        try {
            return match ($event->type) {
                CallbackEvent::PAYMENT_SUCCEEDED => $this->payment($gatewayKey, $event),
                CallbackEvent::PAYMENT_FAILED => $this->failure($gatewayKey, $event),
                CallbackEvent::REFUND => $this->refunded($gatewayKey, $event),
                default => $this->reversed($gatewayKey, $event),
            };
        } catch (BillingException $exception) {
            return $this->outcome($gatewayKey, 422, $exception->getMessage(), $event->raw, $event->invoiceId);
        }
    }

    /**
     * @return Outcome
     */
    private function payment(string $gatewayKey, CallbackEvent $event): array
    {
        $invoice = $event->invoiceId !== null ? Invoice::query()->find($event->invoiceId) : null;

        if ($invoice === null) {
            return $this->outcome($gatewayKey, 404, 'unknown invoice', $event->raw, $event->invoiceId);
        }

        $amount = $event->amount;
        $rate = null;

        if ($event->currency !== $invoice->currency) {
            $gateway = $this->gateways->gateway($gatewayKey);

            if ($gateway->convert_to_currency === null || strtoupper($gateway->convert_to_currency) !== $event->currency) {
                return $this->outcome($gatewayKey, 422, 'currency mismatch', $event->raw, $invoice->id);
            }

            $rate = $this->converter->rate($invoice->currency, $event->currency);
            $amount = $this->converter->convert($event->amount, 1 / $rate);
        }

        $data = new PaymentData(
            amount: $amount,
            gateway: $gatewayKey,
            gatewayTxnId: $event->txnId,
            raw: GatewayLogger::redact($event->raw),
            gatewayAmount: $rate !== null ? $event->amount : null,
            gatewayCurrency: $rate !== null ? $event->currency : null,
            exchangeRate: $rate,
            fromGateway: true,
        );

        if ($this->applyPayment->existing($data) !== null) {
            return $this->outcome($gatewayKey, 200, 'duplicate', $event->raw, $invoice->id);
        }

        $closed = ! $invoice->status->isPayable();
        $this->applyPayment->handle($invoice, $data);

        return $this->outcome($gatewayKey, 200, $closed ? 'payment recorded on closed invoice' : 'payment recorded', $event->raw, $invoice->id);
    }

    /**
     * @return Outcome
     */
    private function failure(string $gatewayKey, CallbackEvent $event): array
    {
        $invoice = $event->invoiceId !== null ? Invoice::query()->find($event->invoiceId) : null;

        if ($invoice === null) {
            return $this->outcome($gatewayKey, 404, 'unknown invoice', $event->raw, $event->invoiceId);
        }

        if ($invoice->status === InvoiceStatus::PaymentPending) {
            $invoice->update(['status' => InvoiceStatus::Unpaid]);
        }

        PaymentFailed::dispatch($invoice->id, $invoice->client_id, $gatewayKey, $event->reason ?? 'payment_failed');

        return $this->outcome($gatewayKey, 200, 'payment failed', $event->raw, $invoice->id);
    }

    /**
     * @return Outcome
     */
    private function refunded(string $gatewayKey, CallbackEvent $event): array
    {
        $parent = $this->parent($gatewayKey, $event);

        if ($parent === null) {
            // Out of order (refund before payment): reject so the provider retries later.
            return $this->outcome($gatewayKey, 409, 'parent transaction not found', $event->raw, $event->invoiceId);
        }

        $this->refund->handle($parent, min($event->amount, $parent->refundable_remaining), RefundMode::RecordOnly, gatewayTxnId: $event->txnId, reason: __('Refunded at the gateway'));

        return $this->outcome($gatewayKey, 200, 'refund recorded', $event->raw, $parent->invoice_id);
    }

    /**
     * @return Outcome
     */
    private function reversed(string $gatewayKey, CallbackEvent $event): array
    {
        $parent = $this->parent($gatewayKey, $event);

        if ($parent === null) {
            return $this->outcome($gatewayKey, 409, 'parent transaction not found', $event->raw, $event->invoiceId);
        }

        $this->chargeback->handle($parent, $event->amount, $event->txnId, GatewayLogger::redact($event->raw));

        return $this->outcome($gatewayKey, 200, 'chargeback recorded', $event->raw, $parent->invoice_id);
    }

    private function parent(string $gatewayKey, CallbackEvent $event): ?Transaction
    {
        return $event->parentTxnId === null ? null : Transaction::query()
            ->where('gateway', $gatewayKey)
            ->where('gateway_txn_id', $event->parentTxnId)
            ->where('type', TransactionType::Payment)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Outcome
     */
    private function outcome(string $gatewayKey, int $status, string $result, array $payload, ?int $invoiceId = null): array
    {
        $this->log->log($gatewayKey, 'callback', $result, $payload, $invoiceId);

        return ['status' => $status, 'result' => $result];
    }
}
