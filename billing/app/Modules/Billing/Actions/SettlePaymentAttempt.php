<?php

namespace App\Modules\Billing\Actions;

use App\Data\Billing\LookupResult;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\GatewayLogger;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * The only way a hosted attempt becomes a payment: an authoritative lookup(), then guards against the stored
 * attempt (status, currency, amount, provider txn id unused) before recording it idempotently. Shared by the
 * return route, the reconciliation poller, webhooks and the admin check.
 *
 * A success the provider confirms for an attempt we already closed (expired, cancelled, replaced) is booked too,
 * after the same lookup and guards: on the invoice up to its balance, the rest (or all of it, when the invoice is
 * no longer payable) as account credit. Every late case is audited and raises a staff to-do (#130).
 */
class SettlePaymentAttempt
{
    public const LATE_NOTE = 'Late payment received after the attempt closed: check and refund if needed';

    public function __construct(
        private GatewayManager $gateways,
        private ApplyPayment $applyPayment,
        private GatewayLogger $log,
        private BillingSettings $settings,
        private ActivityLogger $activity,
    ) {}

    /**
     * @param  bool  $interactive  a customer's browser is waiting (return route): one quick provider try, no retries
     */
    public function handle(PaymentAttempt $attempt, bool $interactive = false): AttemptStatus
    {
        if ($attempt->status === AttemptStatus::Succeeded) {
            return $attempt->status;
        }

        $driver = $this->gateways->hosted($attempt->gateway);

        if ($driver === null) {
            return $attempt->status;
        }

        $result = $driver->lookup($this->gateways->config($attempt->gateway), $attempt->toRef($interactive));
        $raw = GatewayLogger::redact($result->raw);
        $this->log->log($attempt->gateway, 'response', 'lookup '.$result->status, ['reference' => $attempt->reference, ...$raw], $attempt->invoice_id);

        return DB::transaction(function () use ($attempt, $result, $raw): AttemptStatus {
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($attempt->status === AttemptStatus::Succeeded) {
                return $attempt->status;
            }

            $attempt->fill(['last_lookup_at' => now(), 'lookup_count' => $attempt->lookup_count + 1, 'raw' => $raw]);

            if ($result->retryable) {
                $attempt->save();

                return $attempt->status;
            }

            if ($attempt->status->isClosed()) {
                // Only a confirmed success changes a closed attempt; anything else leaves it as it was.
                if ($result->status === LookupResult::SUCCEEDED) {
                    $this->late($attempt, $result);
                }

                $attempt->save();

                return $attempt->status;
            }

            match ($result->status) {
                LookupResult::SUCCEEDED => $this->succeed($attempt, $result),
                LookupResult::FAILED, LookupResult::CANCELLED => $this->fail($attempt, $result),
                LookupResult::PROCESSING => $this->processing($attempt),
                LookupResult::EXPIRED => $attempt->status = AttemptStatus::Expired,
                LookupResult::AMBIGUOUS => $this->review($attempt, is_string($result->raw['error'] ?? null) ? 'Provider: '.$result->raw['error'] : 'The provider could not confirm the payment yet'),
                LookupResult::REFUNDED, LookupResult::PARTIALLY_REFUNDED => $this->review($attempt, 'Refunded at the provider before it was recorded here'),
                default => $this->stillPending($attempt, $result->status),
            };

            $attempt->save();

            return $attempt->status;
        });
    }

    /**
     * A success for a closed attempt: book it through the normal guards (a guard failure sends it to staff review
     * instead), then audit it and flag it for staff.
     */
    private function late(PaymentAttempt $attempt, LookupResult $result): void
    {
        $closedAs = $attempt->status;
        $attempt->late_payment_at = now();
        $transaction = $this->succeed($attempt, $result);
        $credited = $transaction === null ? 0 : (int) CreditLedgerEntry::query()
            ->where('transaction_id', $transaction->id)->where('amount', '>', 0)->sum('amount');

        if ($transaction !== null) {
            $attempt->review_reason = 'Paid at the provider after the attempt was '.$closedAs->value;
        }

        $details = [
            'reference' => $attempt->reference,
            'gateway' => $attempt->gateway,
            'provider_txn_id' => $result->providerTxnId,
            'closed_as' => $closedAs->value,
            'amount' => $attempt->amount,
            'currency' => $attempt->currency,
            'booked' => $transaction !== null,
            'applied' => $transaction === null ? 0 : $transaction->amount_in - $credited,
            'credited' => $credited,
            'document_currency' => $attempt->document_currency,
            'review_reason' => $transaction === null ? $attempt->review_reason : null,
        ];

        $this->activity->log(self::LATE_NOTE, $transaction ?? $attempt, $attempt->client_id, $details, 'billing.payment.late');
        $this->log->log($attempt->gateway, 'callback', $transaction !== null ? 'late payment recorded' : 'late payment held for review', $details, $attempt->invoice_id);
    }

    private function succeed(PaymentAttempt $attempt, LookupResult $result): ?Transaction
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

            return null;
        }

        $invoice = $attempt->invoice_id !== null ? Invoice::query()->find($attempt->invoice_id) : null;

        if ($invoice === null || $invoice->currency !== $attempt->document_currency) {
            // Money was taken but there is nothing to apply it to (e.g. the invoice was deleted), or no rule converts
            // it to the invoice's currency: staff decide.
            $this->review($attempt, $invoice === null
                ? 'Provider reported success but the attempt has no invoice'
                : "Invoice currency {$invoice->currency} differs from the attempt's {$attempt->document_currency}");
            $attempt->provider_txn_id = $result->providerTxnId;

            return null;
        }

        $converted = $attempt->currency !== $attempt->document_currency;

        $transaction = $this->applyPayment->handle($invoice, new PaymentData(
            amount: $attempt->document_amount,
            gateway: $attempt->gateway,
            gatewayTxnId: $result->providerTxnId,
            fee: $result->fee,
            raw: GatewayLogger::redact($result->raw),
            gatewayAmount: $converted ? $attempt->amount : null,
            gatewayCurrency: $converted ? $attempt->currency : null,
            exchangeRate: $converted && $attempt->fx_rate !== null ? (float) $attempt->fx_rate : null,
            fromGateway: true,
        ));
        $attempt->transaction_id = $transaction->id;
        $attempt->provider_txn_id = $result->providerTxnId;
        $attempt->status = AttemptStatus::Succeeded;
        $attempt->review_reason = null;

        return $transaction;
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

    private function fail(PaymentAttempt $attempt, LookupResult $result): void
    {
        $attempt->status = AttemptStatus::Failed;

        if (is_string($result->raw['error'] ?? null)) {
            $attempt->review_reason = mb_substr('Provider: '.$result->raw['error'], 0, 191);
        }
    }

    /**
     * Paid at the provider and still settling: no expiry any more, so the invoice stays locked (PaymentInProgress)
     * until a lookup settles or fails it, or staff resolve it after the grace period.
     */
    private function processing(PaymentAttempt $attempt): void
    {
        $attempt->expires_at = null;
        $this->stillPending($attempt, LookupResult::PROCESSING);
    }

    private function review(PaymentAttempt $attempt, string $reason): void
    {
        $attempt->status = AttemptStatus::Ambiguous;
        $attempt->review_reason = mb_substr($reason, 0, 191);
    }
}
