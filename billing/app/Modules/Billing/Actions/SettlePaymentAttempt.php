<?php

namespace App\Modules\Billing\Actions;

use App\Data\Billing\LookupResult;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Services\GatewayLogger;
use App\Modules\Billing\Support\BillingSettings;
use Illuminate\Support\Facades\DB;

/**
 * The only way a hosted attempt becomes a payment: an authoritative lookup(), then guards against the stored
 * attempt (status, currency, amount, provider txn id unused) before recording it idempotently. Shared by the
 * return route and the reconciliation poller.
 */
class SettlePaymentAttempt
{
    public function __construct(
        private GatewayManager $gateways,
        private ApplyPayment $applyPayment,
        private GatewayLogger $log,
        private BillingSettings $settings,
    ) {}

    public function handle(PaymentAttempt $attempt): AttemptStatus
    {
        if (! $attempt->status->isOpen()) {
            return $attempt->status;
        }

        $driver = $this->gateways->hosted($attempt->gateway);

        if ($driver === null) {
            return $attempt->status;
        }

        $result = $driver->lookup($this->gateways->config($attempt->gateway), $attempt->toRef());
        $raw = GatewayLogger::redact($result->raw);
        $this->log->log($attempt->gateway, 'response', 'lookup '.$result->status, ['reference' => $attempt->reference, ...$raw], $attempt->invoice_id);

        return DB::transaction(function () use ($attempt, $result, $raw): AttemptStatus {
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if (! $attempt->status->isOpen()) {
                return $attempt->status;
            }

            $attempt->fill(['last_lookup_at' => now(), 'lookup_count' => $attempt->lookup_count + 1, 'raw' => $raw]);

            if ($result->retryable) {
                $attempt->save();

                return $attempt->status;
            }

            match ($result->status) {
                LookupResult::SUCCEEDED => $this->succeed($attempt, $result),
                LookupResult::FAILED, LookupResult::CANCELLED => $attempt->status = AttemptStatus::Failed,
                LookupResult::EXPIRED => $attempt->status = AttemptStatus::Expired,
                LookupResult::AMBIGUOUS => $attempt->status = AttemptStatus::Ambiguous,
                LookupResult::REFUNDED, LookupResult::PARTIALLY_REFUNDED => $this->review($attempt, 'Refunded at the provider before it was recorded here'),
                default => $this->stillPending($attempt, $result->status),
            };

            $attempt->save();

            return $attempt->status;
        });
    }

    private function succeed(PaymentAttempt $attempt, LookupResult $result): void
    {
        $reason = match (true) {
            $result->providerTxnId === null || $result->providerTxnId === '' => 'Provider reported success without a transaction id',
            $result->currency !== null && strtoupper($result->currency) !== $attempt->currency => "Currency mismatch: {$result->currency} vs {$attempt->currency}",
            $result->amount !== $attempt->amount => 'Amount mismatch: provider '.($result->amount ?? 'none')." vs expected {$attempt->amount}",
            PaymentAttempt::query()->where('gateway', $attempt->gateway)->where('provider_txn_id', $result->providerTxnId)->whereKeyNot($attempt->id)->exists() => 'Provider transaction already used by another attempt',
            default => null,
        };

        if ($reason !== null) {
            $this->review($attempt, $reason);
            $this->log->log($attempt->gateway, 'callback', 'guard failed', ['reference' => $attempt->reference, 'reason' => $reason], $attempt->invoice_id);

            return;
        }

        $invoice = $attempt->invoice_id !== null ? Invoice::query()->find($attempt->invoice_id) : null;
        $converted = $attempt->currency !== $attempt->document_currency;

        if ($invoice !== null) {
            $transaction = $this->applyPayment->handle($invoice, new PaymentData(
                amount: $attempt->document_amount,
                gateway: $attempt->gateway,
                gatewayTxnId: $result->providerTxnId,
                fee: $result->fee,
                raw: GatewayLogger::redact($result->raw),
                gatewayAmount: $converted ? $attempt->amount : null,
                gatewayCurrency: $converted ? $attempt->currency : null,
                exchangeRate: $converted && $attempt->fx_rate !== null ? (float) $attempt->fx_rate : null,
            ));
            $attempt->transaction_id = $transaction->id;
        }

        $attempt->provider_txn_id = $result->providerTxnId;
        $attempt->status = AttemptStatus::Succeeded;
        $attempt->review_reason = null;
    }

    /**
     * Not found / still pending: keep polling within the grace period. Past it, an attempt the provider never saw
     * expires; one the provider still reports as pending goes to staff review (never auto-failed).
     */
    private function stillPending(PaymentAttempt $attempt, string $status): void
    {
        $graceEnds = ($attempt->expires_at ?? $attempt->created_at ?? now())->addMinutes($this->settings->int('gateways.lookup_grace_minutes', 120));

        if (now()->lt($graceEnds)) {
            return;
        }

        if ($status === LookupResult::NOT_FOUND) {
            $attempt->status = AttemptStatus::Expired;

            return;
        }

        $this->review($attempt, 'Still pending at the provider after the grace period');
    }

    private function review(PaymentAttempt $attempt, string $reason): void
    {
        $attempt->status = AttemptStatus::Ambiguous;
        $attempt->review_reason = mb_substr($reason, 0, 191);
    }
}
