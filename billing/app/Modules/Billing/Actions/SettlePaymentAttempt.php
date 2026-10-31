<?php

namespace App\Modules\Billing\Actions;

use App\Data\Billing\LookupResult;
use App\Data\Billing\ReturnHint;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\GatewayLogger;
use App\Modules\Billing\Services\GatewayManager;
use App\Modules\Billing\Services\PaymentReconciler;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\ActivityLogger;
use App\Support\Money;
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

    public const UNMATCHED_NOTE = 'Provider confirmed a payment its status check cannot find: check the provider portal before anyone pays again';

    public const CREDIT_NOTE = 'Payment went to account credit (invoice already paid or cancelled, or payment held for review): check and refund if needed';

    /**
     * For a not_found lookup flagged sessionEnded: how long the customer's browser has to post the provider form
     * before that answer, without a cancel return, ends the attempt. The form is auto-posted on page load.
     */
    public const NOT_FOUND_SETTLE_MINUTES = 5;

    /** Key in the attempt's raw for the provider-signed paid return (status, amount, code, time). */
    public const SIGNED_RETURN = 'signed_return';

    /**
     * Key in the attempt's raw for a provider-signed cancel return matched to the attempt: every later lookup is told
     * of it (PaymentAttemptRef::$signedCancel), so a cancel that arrives while no lookup is made is not lost.
     */
    public const SIGNED_CANCEL = 'signed_cancel';

    /** Key in the attempt's raw for the form the customer was sent with (StartHostedPayment), kept across lookups. */
    public const PROVIDER_FORM = 'provider_form';

    /** The review reason of an attempt staff closed as not paid: final, no lookup reopens it. */
    public const STAFF_CLOSED_REASON = 'Closed by staff: no payment found at the provider';

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
     * @param  ReturnHint|null  $returnHint  the customer's return, when this settle comes from it: a provider-signed
     *                                       paid status is kept on the attempt as evidence (#323), and so is a
     *                                       signed cancel, which every later lookup is told of
     */
    public function handle(PaymentAttempt $attempt, bool $interactive = false, bool $cancelReturn = false, ?ReturnHint $returnHint = null): AttemptStatus
    {
        $this->lastResult = null;

        if ($attempt->status === AttemptStatus::Succeeded) {
            return $attempt->status;
        }

        $driver = $this->gateways->hosted($attempt->gateway);

        if ($driver === null) {
            return $attempt->status;
        }

        $config = $this->gateways->config($attempt->gateway);
        // Read again under the lock below; a cancel stored between here and there reaches the next lookup.
        $ref = $attempt->toRef($interactive, signedCancel: self::cancelEvidence($returnHint, $attempt) !== null);
        $result = $this->lastResult = $this->gateways->during($config, fn (): LookupResult => $driver->lookup($config, $ref));
        $raw = GatewayLogger::redact($result->raw, $attempt->gateway);
        $this->log->log($attempt->gateway, 'response', 'lookup '.$result->status, ['reference' => $attempt->reference, ...$raw], $attempt->invoice_id);

        return DB::transaction(function () use ($attempt, $result, $raw, $cancelReturn, $returnHint): AttemptStatus {
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($attempt->status === AttemptStatus::Succeeded) {
                return $attempt->status;
            }

            // The provider's signed paid return and the form the customer posted outlive later lookups, which replace
            // raw. Only Billing writes those keys: ones a driver put in its own answer are dropped.
            unset($raw[self::SIGNED_RETURN], $raw[self::SIGNED_CANCEL], $raw[self::PROVIDER_FORM]);
            $signed = self::evidence($returnHint, $attempt);
            $cancelled = self::cancelEvidence($returnHint, $attempt);
            $form = $attempt->raw[self::PROVIDER_FORM] ?? null;
            $raw = is_array($form) ? [...$raw, self::PROVIDER_FORM => $form] : $raw;
            $raw = $cancelled === null ? $raw : [...$raw, self::SIGNED_CANCEL => $cancelled];
            $attempt->fill(['last_lookup_at' => now(), 'lookup_count' => $attempt->lookup_count + 1, 'raw' => $signed === null ? $raw : [...$raw, self::SIGNED_RETURN => $signed]]);

            if ($result->retryable) {
                $attempt->save();

                return $attempt->status;
            }

            if ($attempt->status->isClosed()) {
                match ($result->status) {
                    LookupResult::SUCCEEDED => $this->late($attempt, $result),
                    // Paid after it closed, then partly refunded before we saw it: the provider kept the rest.
                    LookupResult::PARTIALLY_REFUNDED => $this->review($attempt, 'Paid after the attempt closed and partly refunded at the provider'),
                    // Closed, but the provider once signed a paid return: its money may be somewhere we can't see.
                    LookupResult::NOT_FOUND => $signed !== null && $attempt->lookups_closed_at === null ? $this->signedNotFound($attempt, $signed, reopened: true) : $this->closeLookups($attempt, $result),
                    // Anything else leaves it closed; a session the provider says can never be paid is not swept again.
                    default => $this->closeLookups($attempt, $result),
                };

                self::forgetResumeUrl($attempt);
                $attempt->save();

                return $attempt->status;
            }

            match ($result->status) {
                LookupResult::SUCCEEDED => $attempt->late_payment_at !== null ? $this->late($attempt, $result) : $this->succeed($attempt, $result),
                LookupResult::FAILED, LookupResult::CANCELLED => $this->fail($attempt, $result),
                LookupResult::PROCESSING => $this->processing($attempt),
                LookupResult::EXPIRED => $attempt->status = AttemptStatus::Expired,
                LookupResult::AMBIGUOUS => $this->review($attempt, is_string($result->raw['error'] ?? null) ? 'Provider: '.$result->raw['error'] : 'The provider could not confirm the payment yet'),
                LookupResult::REFUNDED, LookupResult::PARTIALLY_REFUNDED => $this->review($attempt, 'Refunded at the provider before it was recorded here'),
                // A signed cancel counts as a cancel return, whichever URL it came back to.
                LookupResult::NOT_FOUND => $this->notFound($attempt, $result->sessionEnded, $cancelReturn || $cancelled !== null, $signed),
                default => $this->stillPending($attempt, $result->status),
            };

            $this->closeLookups($attempt, $result);
            self::forgetResumeUrl($attempt);
            $attempt->save();

            return $attempt->status;
        });
    }

    /**
     * The customer came back with a provider-signed paid or cancel return but no lookup is made now (the floor of the
     * return route): keep it under the attempt's lock anyway. A paid return sends the next lookup that says NOT_FOUND
     * to staff review (#323); a cancel is passed to every later lookup.
     */
    public function keepSignedReturn(PaymentAttempt $attempt, ReturnHint $returnHint): void
    {
        if (! self::verified($returnHint, 'signedPaid') && ! self::verified($returnHint, 'signedCancel')) {
            return;
        }

        DB::transaction(function () use ($attempt, $returnHint): void {
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($locked->status === AttemptStatus::Succeeded || $locked->lookups_closed_at !== null) {
                return;
            }

            $raw = $locked->raw ?? [];
            $signed = self::evidence($returnHint, $locked);
            $cancelled = self::cancelEvidence($returnHint, $locked);
            $raw = $signed === null ? $raw : [...$raw, self::SIGNED_RETURN => $signed];
            $locked->raw = $cancelled === null ? $raw : [...$raw, self::SIGNED_CANCEL => $cancelled];
            $locked->save();
        });
    }

    /**
     * A signed flag counts only with the driver's own verified signature.
     *
     * @param  'signedPaid'|'signedCancel'  $flag
     */
    private static function verified(?ReturnHint $returnHint, string $flag): bool
    {
        return $returnHint !== null && $returnHint->signatureValid && $returnHint->{$flag} === true;
    }

    /**
     * The signed cancel kept on the attempt: this return's when it has one for this attempt, else what an earlier
     * return left. The return must name the attempt (by reference, or by provider reference when it has none); the
     * gateway is matched by the caller (GatewayReturnController). Null when there is none.
     *
     * @return array<string, mixed>|null
     */
    private static function cancelEvidence(?ReturnHint $returnHint, PaymentAttempt $attempt): ?array
    {
        $kept = $attempt->raw[self::SIGNED_CANCEL] ?? null;
        $kept = is_array($kept) ? $kept : null;

        if (! self::verified($returnHint, 'signedCancel')) {
            return $kept;
        }

        $named = $returnHint->reference !== null
            ? $returnHint->reference === $attempt->reference
            : $returnHint->providerRef !== null && $returnHint->providerRef !== '' && $returnHint->providerRef === $attempt->provider_ref;

        return $named ? ($kept ?? ['status' => $returnHint->claimedStatus, 'at' => now()->toIso8601String()]) : $kept;
    }

    /**
     * The provider-signed paid return kept on the attempt: this return's, over what an earlier one left (the marks of
     * what was already done with it survive). Null when there is none.
     *
     * @return array<string, mixed>|null
     */
    private static function evidence(?ReturnHint $returnHint, PaymentAttempt $attempt): ?array
    {
        $kept = $attempt->raw[self::SIGNED_RETURN] ?? null;
        $kept = is_array($kept) ? $kept : null;

        if (! self::verified($returnHint, 'signedPaid')) {
            return $kept;
        }

        return [...($kept ?? []), 'status' => $returnHint->claimedStatus, 'total_amount' => $returnHint->raw['total_amount'] ?? null, 'transaction_code' => $returnHint->providerRef, 'at' => now()->toIso8601String()];
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
        $reopened = $attempt->raw[self::SIGNED_RETURN]['reopened_from'] ?? null;
        $closedAs = is_string($reopened) ? (AttemptStatus::tryFrom($reopened) ?? $attempt->status) : $attempt->status;
        $attempt->late_payment_at ??= now();
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

    /**
     * Staff saw the payment in the provider's portal: book it once, through the same guards as a lookup that says
     * paid. The amount they read there must equal ours exactly (a part or over payment is recorded as a payment on the
     * invoice, not here), and the reference must not be on any booked transaction yet. Nothing is kept when a guard
     * refuses; the audit entry is written in the same transaction as the booking.
     *
     * @param  int  $receivedAmount  minor units the portal shows, in the attempt's currency
     */
    public function resolvePaid(PaymentAttempt $attempt, string $providerReference, int $receivedAmount): PaymentAttempt
    {
        $providerReference = trim($providerReference);

        if ($providerReference === '') {
            throw new BillingException(__('Enter the provider\'s reference for this payment.'));
        }

        return DB::transaction(function () use ($attempt, $providerReference, $receivedAmount): PaymentAttempt {
            $locked = $this->lockedForReview($attempt);

            if ($receivedAmount !== $locked->amount) {
                throw new BillingException(__('The portal shows :seen but this payment is for :expected. Record what was actually received with Add payment on the invoice, and refund or credit the difference.', [
                    'seen' => Money::of($receivedAmount, $locked->currency)->format(),
                    'expected' => $locked->money()->format(),
                ]));
            }

            $booked = $this->applyPayment->existing(new PaymentData(amount: $locked->document_amount, gateway: $locked->gateway, gatewayTxnId: $providerReference));

            if ($booked !== null) {
                $on = $booked->invoice_id !== null ? Invoice::query()->find($booked->invoice_id)?->displayNumber() : null;

                throw new BillingException($on !== null
                    ? __('That reference is already recorded on invoice :number. Check the reference in the portal.', ['number' => $on])
                    : __('That reference is already recorded as a payment. Check the reference in the portal.'));
            }

            $signed = $locked->raw[self::SIGNED_RETURN] ?? null;
            $result = new LookupResult(LookupResult::SUCCEEDED, $providerReference, $locked->amount, $locked->currency, raw: [
                'resolved_by_staff' => true,
                'return_transaction_code' => is_array($signed) && is_string($signed['transaction_code'] ?? null) ? $signed['transaction_code'] : null,
            ]);
            $locked->late_payment_at !== null ? $this->late($locked, $result) : $this->succeed($locked, $result);

            if ($locked->status !== AttemptStatus::Succeeded) {
                throw new BillingException(__('The payment was not recorded: :reason.', ['reason' => $locked->review_reason ?? __('a check failed')]));
            }

            self::forgetResumeUrl($locked);
            $locked->save();
            $this->resolved($locked, 'paid', $providerReference);

            return $locked;
        });
    }

    /**
     * Staff found no payment in the provider's portal: the attempt is closed for good (lookups too, so neither a
     * replayed return nor a lookup answering "not found" reopens it) and the invoice can be paid again. A lookup that
     * finds the payment later still books it as a late payment.
     */
    public function resolveNotPaid(PaymentAttempt $attempt): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt): PaymentAttempt {
            $locked = $this->lockedForReview($attempt);
            $locked->status = AttemptStatus::Failed;
            $locked->review_reason = self::STAFF_CLOSED_REASON;
            $locked->lookups_closed_at = now();
            self::forgetResumeUrl($locked);
            $locked->save();
            $this->resolved($locked, 'not_paid', null);

            return $locked;
        });
    }

    private function resolved(PaymentAttempt $attempt, string $outcome, ?string $providerReference): void
    {
        $this->activity->log($outcome === 'paid' ? 'Gateway payment marked as paid by staff' : 'Gateway payment closed as not paid by staff', $attempt, $attempt->client_id, [
            'reference' => $attempt->reference, 'gateway' => $attempt->gateway, 'amount' => $attempt->amount, 'currency' => $attempt->currency,
            'outcome' => $outcome, 'provider_reference' => $providerReference,
        ], 'billing.payment.resolved');
    }

    private function lockedForReview(PaymentAttempt $attempt): PaymentAttempt
    {
        $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

        if ($locked->status !== AttemptStatus::Ambiguous) {
            throw new BillingException(__('This payment is no longer waiting for review.'));
        }

        return $locked;
    }

    private function credited(Transaction $transaction): int
    {
        return (int) CreditLedgerEntry::query()->where('transaction_id', $transaction->id)->where('amount', '>', 0)->sum('amount');
    }

    private function succeed(PaymentAttempt $attempt, LookupResult $result): ?Transaction
    {
        $reason = match (true) {
            $result->providerTxnId === null || $result->providerTxnId === '' => 'Provider reported success without a transaction id',
            // A success that does not say what was paid is never booked: staff check it at the provider.
            $result->currency === null || $result->currency === '' => 'Provider reported success without a currency',
            $result->amount === null => 'Provider reported success without an amount',
            strtoupper($result->currency) !== $attempt->currency => "Currency mismatch: {$result->currency} vs {$attempt->currency}",
            $result->amount !== $attempt->amount => "Amount mismatch: provider {$result->amount} vs expected {$attempt->amount}",
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
            raw: GatewayLogger::redact($result->raw, $attempt->gateway),
            gatewayAmount: $converted ? $attempt->amount : null,
            gatewayCurrency: $converted ? $attempt->currency : null,
            exchangeRate: $converted && $attempt->fx_rate !== null ? (float) $attempt->fx_rate : null,
            fromGateway: true,
        ));
        // The provider transaction id was already booked on another invoice: that is not this payment.
        if ($transaction->invoice_id !== null && $transaction->invoice_id !== $invoice->id) {
            $this->review($attempt, 'Provider transaction already booked on another invoice');
            $attempt->provider_txn_id = null;

            return null;
        }

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

        // An attempt already in staff review is never expired by "not found": the review decides.
        if ($status === LookupResult::NOT_FOUND && $attempt->status !== AttemptStatus::Ambiguous) {
            $attempt->status = AttemptStatus::Expired;

            return;
        }

        if ($status === LookupResult::NOT_FOUND) {
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
     *
     * @param  array<string, mixed>|null  $signed  the provider-signed paid return kept on the attempt: it goes to review instead
     */
    private function notFound(PaymentAttempt $attempt, bool $sessionEnded, bool $cancelReturn, ?array $signed): void
    {
        if ($signed !== null) {
            $this->signedNotFound($attempt, $signed);

            return;
        }

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

    /**
     * The provider signed a paid return for this attempt, yet its status check can't find the payment under our
     * amount. The attempt goes to staff review with the amount and the evidence kept (never abandoned), and stays
     * open and blocking, so the customer is not asked to pay again. It still settles if a later lookup says COMPLETE.
     *
     * It also stops expiring (as processing() does): the invoice stays blocked until a lookup settles it or staff
     * resolve it (resolvePaid() / resolveNotPaid()). A closed attempt that is reopened keeps what it was closed as,
     * and a later success is booked as the late payment it is.
     *
     * @param  array<string, mixed>  $signed
     */
    private function signedNotFound(PaymentAttempt $attempt, array $signed, bool $reopened = false): void
    {
        if ($reopened && $attempt->status->isClosed()) {
            $signed['reopened_from'] = $attempt->status->value;
            $attempt->late_payment_at ??= now();
        }

        $total = is_string($signed['total_amount'] ?? null) ? str_replace(',', '', $signed['total_amount']) : null;
        $paid = $total !== null && is_numeric($total) ? ' of '.Money::fromDecimal($total, $attempt->currency)->format() : '';
        $code = is_string($signed['transaction_code'] ?? null) ? ' (code '.$signed['transaction_code'].')' : '';
        $this->review($attempt, "Provider signed a paid return{$paid}{$code}, but its status check can't find it under our amount ".$attempt->money()->format().': check the provider portal');
        $attempt->lookups_closed_at = null;
        $attempt->expires_at = null;
        $logged = $signed['logged_at'] ?? null;
        $signed['logged_at'] ??= now()->toIso8601String();
        $attempt->raw = [...($attempt->raw ?? []), self::SIGNED_RETURN => $signed];

        // One entry per attempt, however often the answers flip between lookups.
        if ($logged !== null) {
            return;
        }

        $this->activity->log(self::UNMATCHED_NOTE, $attempt, $attempt->client_id, [
            'reference' => $attempt->reference,
            'gateway' => $attempt->gateway,
            'amount' => $attempt->amount,
            'currency' => $attempt->currency,
            'signed_return' => array_diff_key($signed, ['logged_at' => 1]),
        ], 'billing.payment.unmatched');
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
     * until a lookup settles or fails it, or staff resolve it after the grace period. An attempt already in review keeps
     * its reason.
     */
    private function processing(PaymentAttempt $attempt): void
    {
        $attempt->expires_at = null;

        // Already in staff review: keep the reason it was sent there for (e.g. a failed credit leg).
        if ($attempt->status !== AttemptStatus::Ambiguous) {
            $this->stillPending($attempt, LookupResult::PROCESSING);
        }
    }

    private function review(PaymentAttempt $attempt, string $reason): void
    {
        $attempt->status = AttemptStatus::Ambiguous;
        $attempt->review_reason = mb_substr($reason, 0, 191);
    }
}
