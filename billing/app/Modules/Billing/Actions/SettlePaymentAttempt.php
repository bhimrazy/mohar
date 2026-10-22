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
use App\Modules\Billing\Services\PaymentReconciler;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * The only way a hosted attempt becomes a payment: an authoritative lookup(), then guards against the stored
 * attempt (status, currency, amount, provider txn id unused) before recording it idempotently. Shared by the
 * return route, the reconciliation poller, webhooks and the admin check.
 *
 * A success the provider confirms for an attempt we already closed (expired, failed or cancelled) is booked too,
 * after the same lookup and guards: on the invoice up to its balance, the rest (or all of it, when the invoice is
 * no longer payable) as account credit. Every late case is audited and raises a staff to-do (#130), and so does
 * any hosted payment that goes to account credit (invoice already paid or cancelled, or the payment held for review).
 */
class SettlePaymentAttempt
{
    public const LATE_NOTE = 'Late payment received after the attempt closed: check and refund if needed';

    public const CREDIT_NOTE = 'Payment went to account credit (invoice already paid or cancelled, or payment held for review): check and refund if needed';

    /**
     * For a not_found lookup flagged sessionEnded: how long the customer's browser has to post the provider form
     * before that answer, without a cancel return, ends the attempt. The form is auto-posted on page load.
     */
    public const NOT_FOUND_SETTLE_MINUTES = 5;

    /** The provider's answer to the last lookup this instance made (null when it made none). */
    public ?LookupResult $lastResult = null;

    public function __construct(
        private GatewayManager $gateways,
        private ApplyPayment $applyPayment,
        private GatewayLogger $log,
        private BillingSettings $settings,
        private ActivityLogger $activity,
    ) {}

    /**
     * @param  bool  $interactive  a customer's browser is waiting (return route): one quick provider try, no retries
     * @param  bool  $cancelReturn  the browser came back through the attempt's cancel URL (a hint: only the lookup decides)
     */
    public function handle(PaymentAttempt $attempt, bool $interactive = false, bool $cancelReturn = false): AttemptStatus
    {
        $this->lastResult = null;

        if ($attempt->status === AttemptStatus::Succeeded) {
            return $attempt->status;
        }

        $driver = $this->gateways->hosted($attempt->gateway);

        if ($driver === null) {
            return $attempt->status;
        }

        $result = $this->lastResult = $driver->lookup($this->gateways->config($attempt->gateway), $attempt->toRef($interactive));
        $raw = GatewayLogger::redact($result->raw);
        $this->log->log($attempt->gateway, 'response', 'lookup '.$result->status, ['reference' => $attempt->reference, ...$raw], $attempt->invoice_id);

        return DB::transaction(function () use ($attempt, $result, $raw, $cancelReturn): AttemptStatus {
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
                match ($result->status) {
                    LookupResult::SUCCEEDED => $this->late($attempt, $result),
                    // Paid after it closed, then partly refunded before we saw it: the provider kept the rest.
                    LookupResult::PARTIALLY_REFUNDED => $this->review($attempt, 'Paid after the attempt closed and partly refunded at the provider'),
                    // Anything else leaves it closed; a session the provider says can never be paid is not swept again.
                    default => $this->closeLookups($attempt, $result),
                };

                self::forgetResumeUrl($attempt);
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
                LookupResult::NOT_FOUND => $this->notFound($attempt, $result->sessionEnded, $cancelReturn),
                default => $this->stillPending($attempt, $result->status),
            };

            $this->closeLookups($attempt, $result);
            self::forgetResumeUrl($attempt);
            $attempt->save();

            return $attempt->status;
        });
    }

    /**
     * The kept provider page (#251) is only ever reopened for a Pending attempt (PaymentInProgress::closable()), and
     * an attempt never returns to Pending, so the URL is dropped as soon as the status leaves it.
     */
    private static function forgetResumeUrl(PaymentAttempt $attempt): void
    {
        if ($attempt->status !== AttemptStatus::Pending) {
            $attempt->resume_url = null;
        }
    }

    /**
     * Expired, failed or cancelled at the provider: that session can never be paid, so the late sweep skips it.
     */
    private function closeLookups(PaymentAttempt $attempt, LookupResult $result): void
    {
        if (in_array($result->status, [LookupResult::EXPIRED, LookupResult::FAILED, LookupResult::CANCELLED], true)) {
            $attempt->lookups_closed_at ??= now();
        }
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
        $credited = $transaction === null ? 0 : $this->credited($transaction);

        if ($transaction !== null) {
            $attempt->check_reason = PaymentAttempt::CHECK_LATE;
            $attempt->review_reason = $closedAs === AttemptStatus::Expired
                ? 'Paid at the provider after the attempt expired'
                : 'Paid at the provider after the attempt failed or was cancelled';
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

    private function credited(Transaction $transaction): int
    {
        return (int) CreditLedgerEntry::query()->where('transaction_id', $transaction->id)->where('amount', '>', 0)->sum('amount');
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

        // On time, but it went to credit: the invoice was already paid (e.g. by a late payment first) or cancelled,
        // or the payment was held for review.
        if ($attempt->late_payment_at === null && ($credited = $this->credited($transaction)) > 0) {
            $attempt->check_reason = PaymentAttempt::CHECK_CREDIT;
            $this->activity->log(self::CREDIT_NOTE, $transaction, $attempt->client_id, [
                'reference' => $attempt->reference,
                'gateway' => $attempt->gateway,
                'provider_txn_id' => $result->providerTxnId,
                'applied' => $transaction->amount_in - $credited,
                'credited' => $credited,
            ], 'billing.payment.credited');
        }

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

    /**
     * The provider holds no payable session. When the lookup says so (sessionEnded), a pending attempt ends now, so
     * the customer can pay again at once, if the customer came back through the cancel URL or the form has had
     * NOT_FOUND_SETTLE_MINUTES to reach the provider. A younger attempt may simply not be posted yet, and one in staff
     * review stays there. Lookups stay open, so if a stale form is still paid, the late sweep books it (#130). That
     * backstop is why the late window must cover the attempt (PaymentReconciler::lateSweepCovers()); when it does
     * not, the attempt stays pending and polled as before.
     */
    private function notFound(PaymentAttempt $attempt, bool $sessionEnded, bool $cancelReturn): void
    {
        $settled = ($attempt->created_at ?? now())->lte(now()->subMinutes(self::NOT_FOUND_SETTLE_MINUTES));

        if (! $sessionEnded || ! PaymentReconciler::lateSweepCovers($attempt, $this->settings) || $attempt->status !== AttemptStatus::Pending || (! $cancelReturn && ! $settled)) {
            $this->stillPending($attempt, LookupResult::NOT_FOUND);

            return;
        }

        $attempt->status = $cancelReturn ? AttemptStatus::Failed : AttemptStatus::Expired;
        $attempt->review_reason = $cancelReturn
            ? 'Cancelled by the customer: the provider has no payable session'
            : 'Abandoned: the provider has no payable session';
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
