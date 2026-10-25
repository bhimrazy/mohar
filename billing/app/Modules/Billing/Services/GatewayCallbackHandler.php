<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\GatewayDriver;
use App\Data\Billing\CallbackEvent;
use App\Data\Billing\CallbackRejection;
use App\Events\Billing\PaymentFailed;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Actions\RecordChargeback;
use App\Modules\Billing\Actions\RecordDisputeWon;
use App\Modules\Billing\Actions\RefundAccountCredit;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Exceptions\ReversalAlreadyRecorded;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\PaymentBatch;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative gateway callbacks (§6): verify signature → resolve invoice → idempotency → amount/currency
 * check → record → gateway_log. Return URLs are never trusted to mark anything paid.
 *
 * @phpstan-type Outcome array{status: int, result: string}
 */
class GatewayCallbackHandler
{
    /**
     * A refund or dispute for a payment not booked yet (it arrived first: Stripe can send a dispute before the payment's
     * own events). 409 makes the provider deliver it again, which books it; the log says so rather than reporting an
     * error (#124).
     */
    public const PAYMENT_NOT_BOOKED = 'payment not booked yet; retry expected';

    /** A won dispute whose chargeback is not booked yet (the win arrived first): 409, so the provider retries. */
    public const DISPUTE_NOT_BOOKED = 'dispute not booked yet; retry expected';

    /** A won dispute the provider withdrew and reinstated before any chargeback was booked here (#232): 200, a zero-amount marker. */
    public const DISPUTE_WON_UNBOOKED = 'dispute won with no chargeback booked; funds reinstated at the provider, no money moved';

    /** A chargeback for a dispute already won (its win, or the #232 marker, is booked): 200, nothing booked. */
    public const DISPUTE_ALREADY_WON = 'dispute already won; nothing booked';

    public const MAX_CALLBACK_AGE_SECONDS = 300;

    /** Larger bodies are refused before any signature work (no provider sends callbacks near this size). */
    public const MAX_BODY_BYTES = 262_144;

    public function __construct(
        private GatewayManager $gateways,
        private GatewayLogger $log,
        private CurrencyConverter $converter,
        private ApplyPayment $applyPayment,
        private RefundTransaction $refund,
        private RecordChargeback $chargeback,
        private GatewayAmounts $amounts,
        private SettlePaymentAttempt $settle,
        private CreditLedgerService $credit,
        private RecordDisputeWon $disputeWon,
        private ActivityLogger $activity,
        private RefundAccountCredit $creditRefunds,
    ) {}

    /**
     * @return Outcome HTTP status for the provider (4xx/5xx makes it retry) and the logged result
     */
    public function handle(string $gatewayKey, Request $request): array
    {
        $declared = $request->header('Content-Length');

        if ((is_numeric($declared) && (int) $declared > self::MAX_BODY_BYTES) || strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return $this->outcome($gatewayKey, 413, 'payload too large', ['ip' => $request->ip(), 'length' => is_numeric($declared) ? (int) $declared : strlen($request->getContent())]);
        }

        // Nothing from an unverified body is stored: rejections log metadata only.
        $payload = [
            'ip' => $request->ip(),
            'length' => strlen($request->getContent()),
            'sha256' => hash('sha256', $request->getContent()),
        ];

        if (! $this->gateways->has($gatewayKey) || ! $this->gateways->isActive($gatewayKey)) {
            return $this->outcome($gatewayKey, 404, 'gateway not active', $payload);
        }

        $gateway = $this->gateways->gateway($gatewayKey);

        $driver = $this->gateways->driver($gatewayKey);

        if (! $driver->supports(GatewayDriver::WEBHOOK)) {
            return $this->outcome($gatewayKey, 404, 'callbacks not supported', $payload);
        }

        $config = $this->gateways->config($gateway);
        $event = $this->gateways->during($config, fn (): CallbackEvent|CallbackRejection => $driver->parseCallback($config, $request));

        if ($event instanceof CallbackRejection) {
            return $this->outcome($gatewayKey, $event->isSignatureFailure() ? 403 : 422, $event->reason, $payload);
        }

        // Replay protection on top of provider-id idempotency: signed send times must be within 5 minutes.
        if ($event->sentAt !== null && abs(now()->getTimestamp() - $event->sentAt->getTimestamp()) > self::MAX_CALLBACK_AGE_SECONDS) {
            return $this->outcome($gatewayKey, 400, 'stale callback', [...$payload, 'sent_at' => $event->sentAt->toIso8601String()]);
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
                CallbackEvent::REFUND_FAILED => $this->refundFailed($gatewayKey, $event),
                CallbackEvent::CHARGEBACK => $this->reversed($gatewayKey, $event),
                CallbackEvent::DISPUTE_WON => $this->won($gatewayKey, $event),
                CallbackEvent::DISPUTE_INQUIRY => $this->inquiry($gatewayKey, $event),
                CallbackEvent::ATTEMPT_UPDATED => $this->attemptUpdated($gatewayKey, $event),
                // Unknown event types are acknowledged and ignored, never booked as money movements.
                default => $this->outcome($gatewayKey, 200, 'ignored event type', ['type' => $event->type, 'txn_id' => $event->txnId], $event->invoiceId),
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
            raw: GatewayLogger::redact($event->raw, $gatewayKey),
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
     * A hosted attempt changed at the provider: settle it exactly like the return route and the poller do (lookup,
     * then the guards against the attempt's stored amount, currency and rate), also when the attempt is already
     * closed. The event's own figures are unused.
     *
     * @return Outcome
     */
    private function attemptUpdated(string $gatewayKey, CallbackEvent $event): array
    {
        $attempt = $event->reference !== null
            ? PaymentAttempt::query()->where('gateway', $gatewayKey)->where('reference', $event->reference)->first()
            : null;

        if ($attempt === null) {
            // Retrying won't create it: acknowledge so the provider stops.
            return $this->outcome($gatewayKey, 200, 'unknown attempt', ['reference' => $event->reference, ...$event->raw]);
        }

        $before = $attempt->status;
        $status = $this->settle->handle($attempt);
        // A closed attempt is looked up like an open one: a success the provider confirms is booked late (#130).
        $result = match (true) {
            $status === AttemptStatus::Succeeded && $before === AttemptStatus::Succeeded => 'duplicate',
            $status === AttemptStatus::Succeeded && $before->isClosed() => 'late payment recorded',
            $status === AttemptStatus::Succeeded => 'payment recorded',
            default => 'attempt '.$status->value,
        };

        return $this->outcome($gatewayKey, 200, $result, ['reference' => $attempt->reference, ...$event->raw], $attempt->invoice_id);
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
        $known = $event->txnId !== '' ? $this->refund->existing($gatewayKey, $event->txnId) : null;

        // A pending row can already hold the provider's refund id: an unknown answer (a pending state) that carried it.
        // The callback is the provider's word on that row, so it is settled below like any pending refund we sent.
        if ($known !== null && $known->status !== TransactionStatus::Pending) {
            return $this->outcome($gatewayKey, 200, 'duplicate', $event->raw, $event->invoiceId);
        }

        // A refund started from our admin names its refund row. A row that recorded it ends here, a pending one is
        // settled below, and one marked failed (declined or released, yet the refund happened) is booked now.
        $own = $known ?? ($event->reference !== null && ctype_digit($event->reference)
            ? Transaction::query()->whereKey((int) $event->reference)->where('gateway', $gatewayKey)->where('type', TransactionType::Refund)->first()
            : null) ?? $this->releasedRefund($gatewayKey, $event);

        if ($own?->status === TransactionStatus::Succeeded) {
            return $this->outcome($gatewayKey, 200, 'refund already recorded', $event->raw, $own->invoice_id);
        }

        // A refund we sent and have not booked (its answer never came, or its request is still running) holds its
        // amount: the callback is the provider's word that it happened, so it settles that same row (never a second
        // one). It must be the refund we sent: same payment, same amount in what the provider charged.
        $creditRefund = $own?->isCreditRefund() === true;

        // A refund of account credit is a payout, never an invoice refund: it is settled here and takes no part in the parents path below
        // (ADR 0024 §5a). Failed rows are paid after a decline.
        if ($creditRefund && $own->status !== TransactionStatus::Succeeded && $event->txnId !== '') {
            $parent = Transaction::query()->find($own->parent_transaction_id);
            $sent = $parent !== null ? $this->amounts->toGateway($parent, $own->amount_out) : null;
            $samePayment = $parent !== null && ($event->parentTxnId === null || $event->parentTxnId === $this->amounts->charge($parent)['txnId']);

            if ($sent === null || ! $samePayment || $sent->amount !== $event->amount || $sent->currency !== strtoupper($event->currency)) {
                $flagged = $this->creditRefunds->flagLateForReview($own, 'the amount differs');

                return $this->outcome($gatewayKey, 200, $flagged ? 'refund differs from the credit refund; put on Disputes to review' : 'refund differs from the credit refund; the row is still pending', $event->raw, null);
            }

            $wasFailed = $own->status === TransactionStatus::Failed;
            $settled = $this->refund->settle($own, $event->txnId, $event->raw);

            return $this->outcome($gatewayKey, 200, match (true) {
                $wasFailed => 'credit refund paid after a decline; staff review',
                $settled->review_amount !== null => 'credit refund recorded; staff review',
                default => 'credit refund recorded',
            }, $event->raw, null);
        }

        if ($creditRefund) {
            return $own->status === TransactionStatus::Pending
                ? $this->outcome($gatewayKey, 409, 'refund still being recorded; retry expected', $event->raw, null)
                : $this->outcome($gatewayKey, 200, $own->status === TransactionStatus::Succeeded ? 'refund already recorded' : ($this->creditRefunds->flagLateForReview($own, 'no refund id') ? 'credit refund callback without a refund id; put on Disputes to review' : 'credit refund callback without a refund id'), $event->raw, null);
        }

        if ($own?->status === TransactionStatus::Pending && $event->txnId !== '') {
            $parent = Transaction::query()->find($own->parent_transaction_id);
            $sent = $parent !== null ? $this->amounts->toGateway($parent, $own->amount_out) : null;
            $samePayment = $parent !== null && ($event->parentTxnId === null || $event->parentTxnId === $this->amounts->charge($parent)['txnId']);

            if ($sent === null || ! $samePayment || $sent->amount !== $event->amount || $sent->currency !== strtoupper($event->currency)) {
                return $this->outcome($gatewayKey, 200, 'refund differs from the pending refund; staff review', $event->raw, $own->invoice_id);
            }

            $settled = $this->refund->settle($own, $event->txnId, $event->raw);

            // The request's DECLINED (or staff, or Check refund) may have released the row between the read above and the lock settle
            // takes: the provider paid all the same, so it is booked below as a provider-initiated refund (ADR 0024 §5).
            if ($settled->status !== TransactionStatus::Failed) {
                return $this->outcome($gatewayKey, 200, 'refund recorded', $event->raw, $own->invoice_id);
            }

            $own = $settled;
        }

        if ($own?->status === TransactionStatus::Pending) {
            return $this->outcome($gatewayKey, 409, 'refund still being recorded; retry expected', $event->raw, $own->invoice_id);
        }

        $parents = $this->parents($gatewayKey, $event);

        // The refund we marked failed (declined, or released by staff) was made at the provider after all: it is
        // booked below, once by its provider refund id, and left on staff's to-do list.
        $lateAfterFailure = $own?->status === TransactionStatus::Failed;

        if ($parents === []) {
            // Out of order (refund before payment): reject so the provider retries later.
            return $this->outcome($gatewayKey, 409, self::PAYMENT_NOT_BOOKED, $event->raw, $event->invoiceId);
        }

        // The refund rows and any credit taken back commit together (all record-only, no provider call), so a crash
        // can't leave the credit in place while the provider's retry is answered "duplicate".
        try {
            return DB::transaction(function () use ($gatewayKey, $event, $parents, $lateAfterFailure): array {
                $parents = $this->lockParents($parents);

                // The dispute is already won at the provider (#232): a chargeback built from a dispute retrieved before
                // the win must not be booked, since nothing would ever reverse it.
                if ($event->txnId !== '' && $this->disputeWon->existing($gatewayKey, $event->txnId) !== null) {
                    return $this->outcome($gatewayKey, 200, self::DISPUTE_ALREADY_WON, $event->raw, $parents[0]->invoice_id);
                }

                $remaining = $this->amounts->toDocument($parents[0], $event->amount, $event->currency);
                $refundedTotal = $remaining;

                // Credit first, then the invoice: the reverse of how the money arrived, since only the surplus went to
                // account credit (#194). The credit is what those payments placed: an overpayment, a payment of a
                // closed invoice, a refund to credit (#172, and #152 for flagged attempts).
                $taken = $event->txnId !== ''
                    ? $this->reverseCredit($gatewayKey, $event->txnId, $parents, $remaining, CreditReason::CreditRemoved, __('Refunded at the gateway (:refund)', ['refund' => $event->txnId]))
                    : 0;
                $remaining -= $taken;
                $txnId = $event->txnId;
                $first = null;

                foreach ($parents as $parent) {
                    $portion = min($remaining, $parent->refundable_remaining);

                    if ($portion <= 0) {
                        continue;
                    }

                    $first ??= $this->refund->handle($parent, $portion, RefundMode::RecordOnly, gatewayTxnId: $txnId, reason: __('Refunded at the gateway'));
                    $remaining -= $portion;
                    $txnId = null;
                }

                if ($taken === 0 && $remaining === 0) {
                    if ($lateAfterFailure && $first !== null) {
                        $this->flagLate($first, $refundedTotal);
                    }

                    // Part of it may have gone to "Disputes to review" (the invoice's cap and the credit notes placed could not absorb it).
                    if ($first !== null && $first->fresh()?->review_amount !== null) {
                        return $this->outcome($gatewayKey, 200, 'refund recorded; part of it needs staff review', [...$event->raw, 'to_review' => $first->fresh()->review_amount], $parents[0]->invoice_id);
                    }

                    return $this->outcome($gatewayKey, 200, 'refund recorded', $event->raw, $parents[0]->invoice_id);
                }

                $left = $remaining;
                $payload = [...$event->raw, 'credit_taken_back' => $taken, 'unallocated' => $left];
                $flagged = $this->flaggedAttempt($parents);

                // The refund books everything the provider refunded as money out; a credit-only refund gets its own row,
                // which also carries the refund id for replays.
                $first ??= $this->reversalRow($parents[0], TransactionType::Refund, $event, __('Refunded at the gateway'));
                $this->finishReversal($first, $taken, $left, review: $flagged === null);
                if ($lateAfterFailure) {
                    $this->flagLate($first, $refundedTotal);
                }

                $payment = $flagged !== null ? (collect($parents)->firstWhere('id', $flagged->transaction_id) ?? $parents[0]) : $parents[0];

                if ($flagged !== null && ($left > 0 || $this->credit->balance($payment->client_id, $payment->currency)->isNegative())) {
                    // A flagged late or credited payment keeps its own to-do when the credit could not cover the refund.
                    $flagged->update([
                        'check_reason' => PaymentAttempt::CHECK_REFUND,
                        'late_checked_at' => null,
                        'review_reason' => mb_substr('Refunded at the provider beyond the account credit left', 0, 191),
                    ]);

                    return $this->outcome($gatewayKey, 200, 'refund needs staff review', $payload, $payment->invoice_id);
                }

                if ($left > 0) {
                    // More than the payments and the credit they placed: on "Disputes to review".
                    return $this->outcome($gatewayKey, 200, 'refund needs staff review', $payload, $parents[0]->invoice_id);
                }

                return $this->outcome($gatewayKey, 200, 'refund recorded; credit taken back', $payload, $parents[0]->invoice_id);
            });
        } catch (UniqueConstraintViolationException|ReversalAlreadyRecorded) {
            // Another delivery of the same refund booked it first (refund row or credit line).
            return $this->outcome($gatewayKey, 200, 'duplicate', $event->raw, $parents[0]->invoice_id);
        }
    }

    /**
     * The provider reports a refund as failed (#498). Nothing is booked or reversed here. A refund we booked as made
     * goes on "Disputes to review", once. A pending one is marked under its lock and left to Check refund, which asks
     * the provider and releases it; if it is booked anyway (a "succeeded" delivery settling it meanwhile), the booking
     * puts it on review. One we never recorded, or already released, changes nothing. The row is matched by the
     * provider refund id, or by our reference when it holds no other id, and must belong to the payment the event names.
     *
     * @return Outcome
     */
    private function refundFailed(string $gatewayKey, CallbackEvent $event): array
    {
        if ($event->txnId === '') {
            return $this->outcome($gatewayKey, 200, 'failed refund without an id; nothing changed', $event->raw);
        }

        $row = $this->failedRefundRow($gatewayKey, $event);
        $parents = $row === null ? $this->parents($gatewayKey, $event) : [];

        if ($parents !== []) {
            // A refund callback booking this refund holds these payment locks until it commits: wait for it, then look again.
            DB::transaction(fn () => $this->lockParents($parents));
            $row = $this->failedRefundRow($gatewayKey, $event);
        }

        if ($row === null) {
            return $this->outcome($gatewayKey, 200, 'failed refund not recorded here; nothing changed', $event->raw);
        }

        $parent = Transaction::query()->find($row->parent_transaction_id);

        if ($parent === null || ($event->parentTxnId !== null && $event->parentTxnId !== $this->amounts->charge($parent)['txnId'])) {
            return $this->outcome($gatewayKey, 200, 'failed refund names another payment; nothing changed', $event->raw, $row->invoice_id);
        }

        try {
            $reported = $this->amounts->toDocument($parent, $event->amount, $event->currency);
        } catch (BillingException) {
            // A currency we can't convert: the booked amount is still reviewed.
            $reported = 0;
        }

        $result = match ($this->refund->failedAtProvider($row, $reported)) {
            RefundTransaction::FAILED_FLAGGED => 'refund failed at the provider after it was booked; put on Disputes to review',
            RefundTransaction::FAILED_PENDING => 'refund failed at the provider; Check refund releases it',
            RefundTransaction::FAILED_RELEASED => 'refund already released; nothing changed',
            default => 'duplicate',
        };

        return $this->outcome($gatewayKey, 200, $result, $event->raw, $row->invoice_id);
    }

    private function failedRefundRow(string $gatewayKey, CallbackEvent $event): ?Transaction
    {
        return $this->refund->existing($gatewayKey, $event->txnId)
            ?? ($event->reference !== null && ctype_digit($event->reference)
                ? Transaction::query()->whereKey((int) $event->reference)->where('gateway', $gatewayKey)->where('type', TransactionType::Refund)
                    ->where(fn ($own) => $own->whereNull('gateway_txn_id')->orWhere('gateway_txn_id', $event->txnId))->first()
                : null);
    }

    /**
     * A refund row we released (failed) while it held this provider refund id: a report that the refund was made is
     * then a late refund after a failure (booked once, staff review). Looked up among the payment's refund rows.
     */
    private function releasedRefund(string $gatewayKey, CallbackEvent $event): ?Transaction
    {
        $parents = $event->txnId !== '' ? $this->parents($gatewayKey, $event) : [];

        if ($parents === []) {
            return null;
        }

        return Transaction::query()->whereIn('parent_transaction_id', array_map(fn (Transaction $parent): int => $parent->id, $parents))
            ->where('type', TransactionType::Refund)->where('status', TransactionStatus::Failed)->orderBy('id')->get()
            ->first(fn (Transaction $row): bool => ($row->raw[Transaction::REFUND_STATE][Transaction::RELEASED_REFUND_ID] ?? null) === $event->txnId);
    }

    /**
     * The hosted attempt behind one of these payments, when it was flagged for staff (late, or gone to credit): its
     * money went to account credit, so a refund at the provider takes that credit back (#130).
     *
     * @param  list<Transaction>  $parents
     */
    private function flaggedAttempt(array $parents): ?PaymentAttempt
    {
        return PaymentAttempt::query()
            ->whereIn('transaction_id', array_map(fn (Transaction $parent): int => $parent->id, $parents))
            ->whereNotNull('check_reason')
            ->orderBy('id')
            ->first();
    }

    /**
     * A dispute (chargeback): each payment gives back what it can still return, what they can't (money that went to
     * account credit) is taken back from that credit, and the chargeback books the whole disputed amount (#172).
     * One transaction, so a crash never leaves the chargeback without its credit line. A replay is stopped by the
     * chargeback row (under the payment lock); a credit line written without one ends as "duplicate" too.
     *
     * @return Outcome
     */
    private function reversed(string $gatewayKey, CallbackEvent $event): array
    {
        if ($this->chargeback->existing($gatewayKey, $event->txnId) !== null) {
            return $this->outcome($gatewayKey, 200, 'duplicate', $event->raw, $event->invoiceId);
        }

        $parents = $this->parents($gatewayKey, $event);

        if ($parents === []) {
            return $this->outcome($gatewayKey, 409, self::PAYMENT_NOT_BOOKED, $event->raw, $event->invoiceId);
        }

        try {
            return DB::transaction(function () use ($gatewayKey, $event, $parents): array {
                $parents = $this->lockParents($parents);

                // The dispute is already won at the provider (#232): a chargeback built from a dispute retrieved before
                // the win must not be booked, since nothing would ever reverse it.
                if ($event->txnId !== '' && $this->disputeWon->existing($gatewayKey, $event->txnId) !== null) {
                    return $this->outcome($gatewayKey, 200, self::DISPUTE_ALREADY_WON, $event->raw, $parents[0]->invoice_id);
                }

                $remaining = $this->amounts->toDocument($parents[0], $event->amount, $event->currency);

                // Credit first, then the invoice, as for refunds (#194).
                $taken = $event->txnId !== ''
                    ? $this->reverseCredit($gatewayKey, $event->txnId, $parents, $remaining, CreditReason::Reversal, __('Credit reversed (chargeback :dispute)', ['dispute' => $event->txnId]))
                    : 0;
                $remaining -= $taken;
                $first = null;

                foreach ($parents as $index => $parent) {
                    $portion = min($remaining, $parent->refundable_remaining);

                    if ($portion <= 0 && $index > 0) {
                        continue;
                    }

                    // Only the first row carries the provider's dispute id (unique); further allocations reference the batch.
                    $chargeback = $this->chargeback->handle($parent, $portion, $index === 0 ? $event->txnId : null, GatewayLogger::redact($event->raw, $gatewayKey));

                    if ($index === 0 && ! $chargeback->wasRecentlyCreated) {
                        // A delivery of this dispute committed first: roll back the credit taken above too.
                        throw new ReversalAlreadyRecorded;
                    }

                    $first ??= $chargeback;
                    $remaining -= $chargeback->amount_out;
                }

                $this->closeDisputedCheck($parents, $event->txnId);
                // An inquiry that escalated: its to-do is replaced by the chargeback (#212).
                Transaction::query()->where('gateway', $gatewayKey)->where('gateway_txn_id', $event->txnId)
                    ->where('type', TransactionType::DisputeInquiry)->whereNull('reviewed_at')->update(['reviewed_at' => now()]);

                if ($taken === 0 && $remaining === 0) {
                    return $this->outcome($gatewayKey, 200, 'chargeback recorded', $event->raw, $parents[0]->invoice_id);
                }

                $left = $remaining;
                $this->finishReversal($first, $taken, $left, review: true);
                $payload = [...$event->raw, 'credit_taken_back' => $taken, 'unallocated' => $left];

                return $this->outcome($gatewayKey, 200, match (true) {
                    $left > 0 => 'chargeback needs staff review',
                    default => 'chargeback recorded; credit taken back',
                }, $payload, $parents[0]->invoice_id);
            });
        } catch (UniqueConstraintViolationException|ReversalAlreadyRecorded) {
            return $this->outcome($gatewayKey, 200, 'duplicate', $event->raw, $parents[0]->invoice_id);
        }
    }

    /**
     * Lock the payments, then their invoices, before any credit moves (the order every money path uses), and read the
     * payments again under the lock: what they can still return is decided on fresh rows.
     *
     * @param  list<Transaction>  $parents
     * @return list<Transaction>
     */
    private function lockParents(array $parents): array
    {
        $ids = array_map(fn (Transaction $parent): int => $parent->id, $parents);
        $locked = Transaction::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        Invoice::query()->whereIn('id', $locked->pluck('invoice_id')->filter()->unique())->orderBy('id')->lockForUpdate()->get();

        return array_map(fn (int $id): Transaction => $locked->get($id) ?? throw new BillingException(__('The payment no longer exists.')), $ids);
    }

    /**
     * A flagged late or credited payment that is disputed leaves "Late payments to check": the dispute reversed it, and
     * what is still open is on the dispute's own to-dos (#194). The attempt says why.
     *
     * @param  list<Transaction>  $parents
     */
    private function closeDisputedCheck(array $parents, string $disputeId): void
    {
        $attempts = PaymentAttempt::toCheck([PaymentAttempt::CHECK_LATE, PaymentAttempt::CHECK_CREDIT])
            ->whereIn('transaction_id', array_map(fn (Transaction $parent): int => $parent->id, $parents))
            ->lockForUpdate()->get();

        foreach ($attempts as $attempt) {
            $note = 'disputed at the provider ('.$disputeId.')';
            $attempt->update([
                'late_checked_at' => now(),
                'review_reason' => mb_substr($attempt->review_reason === null ? ucfirst($note) : $attempt->review_reason.'; '.$note, 0, 191),
            ]);
            $this->activity->log('Flagged gateway payment closed by a dispute', $attempt, $attempt->client_id, [
                'reference' => $attempt->reference, 'gateway' => $attempt->gateway, 'check_reason' => $attempt->check_reason, 'dispute' => $disputeId,
            ], 'billing.payment.late_closed_by_dispute');
        }
    }

    /**
     * A dispute the merchant won: RecordDisputeWon puts back what its chargeback booked, once per dispute id (#191).
     *
     * @return Outcome
     */
    private function won(string $gatewayKey, CallbackEvent $event): array
    {
        if ($event->txnId === '' || $this->disputeWon->existing($gatewayKey, $event->txnId) !== null) {
            return $this->outcome($gatewayKey, 200, $event->txnId === '' ? 'dispute without an id' : 'duplicate', $event->raw, $event->invoiceId);
        }

        $chargeback = $this->chargeback->existing($gatewayKey, $event->txnId);

        if ($chargeback === null && $event->fundsReinstated) {
            $unbooked = $this->wonUnbooked($gatewayKey, $event);

            if ($unbooked !== null) {
                return $unbooked;
            }

            // A chargeback committed while we waited for the payment lock: reverse it as usual.
            $chargeback = $this->chargeback->existing($gatewayKey, $event->txnId);
        }

        if ($chargeback === null) {
            return $this->outcome($gatewayKey, 409, self::DISPUTE_NOT_BOOKED, $event->raw, $event->invoiceId);
        }

        try {
            $won = $this->disputeWon->handle($chargeback, GatewayLogger::redact($event->raw, $gatewayKey));
        } catch (UniqueConstraintViolationException) {
            // Another delivery of the same win committed first.
            return $this->outcome($gatewayKey, 200, 'duplicate', $event->raw, $chargeback->invoice_id);
        }

        if (! $won->wasRecentlyCreated) {
            return $this->outcome($gatewayKey, 200, 'duplicate', $event->raw, $chargeback->invoice_id);
        }

        return $this->outcome($gatewayKey, 200, $won->review_amount !== null ? 'dispute won; needs staff review' : 'dispute won; payment reinstated', [
            ...$event->raw, 'reinstated' => $won->amount_in, 'review' => $won->review_amount,
        ], $chargeback->invoice_id);
    }

    /**
     * A won dispute the provider withdrew and reinstated before any chargeback was booked here (an escalated inquiry
     * on an install not subscribed to the withdrawal, #232). No money moved on our side, and a retry would never find
     * a chargeback, so the win is answered 200. Under the payment lock (the lock the chargeback path takes) it either
     * finds a chargeback that committed meanwhile (null: the caller reverses it), or writes a zero-amount
     * `chargeback_reversal` row for the dispute id: the durable record that the dispute is won, which a chargeback
     * delivered later (a stale funds_withdrawn) checks under the same lock, so it books nothing. Unique on
     * `(gateway, gateway_txn_id, type)`.
     *
     * @return Outcome|null
     */
    private function wonUnbooked(string $gatewayKey, CallbackEvent $event): ?array
    {
        $parents = $this->parents($gatewayKey, $event);

        if ($parents === []) {
            return $this->outcome($gatewayKey, 409, self::PAYMENT_NOT_BOOKED, $event->raw, $event->invoiceId);
        }

        try {
            return DB::transaction(function () use ($gatewayKey, $event, $parents): ?array {
                $parents = $this->lockParents($parents);

                if ($this->chargeback->existing($gatewayKey, $event->txnId) !== null) {
                    return null;
                }

                if ($this->disputeWon->existing($gatewayKey, $event->txnId) !== null) {
                    return $this->outcome($gatewayKey, 200, 'duplicate', $event->raw, $parents[0]->invoice_id);
                }

                $this->reversalRow($parents[0], TransactionType::ChargebackReversal, $event, __('Dispute won at the provider before a chargeback was booked here. No money moved.'));

                return $this->outcome($gatewayKey, 200, self::DISPUTE_WON_UNBOOKED, $event->raw, $parents[0]->invoice_id);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->outcome($gatewayKey, 200, 'duplicate', $event->raw, $parents[0]->invoice_id);
        }
    }

    /**
     * A dispute opened with no money withdrawn (an inquiry, #212): one zero-amount row per dispute id, open on
     * "Disputes to review" until staff mark it reviewed or the dispute's chargeback is booked. It moves no money:
     * the payment, invoice and credit are untouched, and its close changes nothing. Idempotent on the unique
     * (gateway, gateway_txn_id, type) index; a chargeback already booked for the dispute makes it a duplicate.
     *
     * @return Outcome
     */
    private function inquiry(string $gatewayKey, CallbackEvent $event): array
    {
        $recorded = Transaction::query()->where('gateway', $gatewayKey)->where('gateway_txn_id', $event->txnId)
            ->whereIn('type', [TransactionType::DisputeInquiry, TransactionType::Chargeback])->exists();

        if ($event->txnId === '' || $recorded) {
            return $this->outcome($gatewayKey, 200, 'duplicate', $event->raw, $event->invoiceId);
        }

        $parents = $this->parents($gatewayKey, $event);

        if ($parents === []) {
            return $this->outcome($gatewayKey, 409, self::PAYMENT_NOT_BOOKED, $event->raw, $event->invoiceId);
        }

        try {
            $this->reversalRow($parents[0], TransactionType::DisputeInquiry, $event, __('Dispute inquiry at the provider: answer it there. No money moved.'), review: 0);
        } catch (UniqueConstraintViolationException) {
            return $this->outcome($gatewayKey, 200, 'duplicate', $event->raw, $parents[0]->invoice_id);
        }

        return $this->outcome($gatewayKey, 200, 'dispute inquiry recorded; no money moved', $event->raw, $parents[0]->invoice_id);
    }

    /**
     * A refund row for a provider refund none of the payments could absorb (all of it went to account credit): it
     * changes neither the payment's refundable amount nor the invoice, and carries the provider's refund id.
     */
    private function reversalRow(Transaction $payment, TransactionType $type, CallbackEvent $event, string $description, ?int $review = null): Transaction
    {
        return Transaction::query()->create([
            'client_id' => $payment->client_id,
            'invoice_id' => $payment->invoice_id,
            'payment_batch_id' => $payment->payment_batch_id,
            'gateway' => $payment->gateway,
            'gateway_txn_id' => $event->txnId !== '' ? $event->txnId : null,
            'type' => $type,
            'status' => TransactionStatus::Succeeded,
            'amount_out' => 0,
            'currency' => $payment->currency,
            'parent_transaction_id' => $payment->id,
            'description' => $description,
            'raw' => GatewayLogger::redact($event->raw, $payment->gateway),
            'occurred_at' => now(),
            'review_amount' => $review,
        ]);
    }

    /**
     * Book the rest of a provider reversal on its first row: what came back from credit and what nothing absorbed
     * both left the bank, so they are money out (net income stays right). The unabsorbed part is put up for staff
     * review ("Disputes to review") unless a flagged attempt carries it.
     */
    private function finishReversal(Transaction $row, int $taken, int $left, bool $review): void
    {
        $row->update([
            'amount_out' => $row->amount_out + $taken + $left,
            'review_amount' => max($row->review_amount ?? 0, $review && $left > 0 ? $left : 0) ?: null,
        ]);
    }

    /**
     * A refund we had marked failed was made at the provider after all. It is booked once, and staff review it
     * whatever absorbed it: the review amount is never lowered, and a reviewed flag is reopened.
     */
    private function flagLate(Transaction $row, int $amount): void
    {
        $row->update([
            'review_amount' => max($row->review_amount ?? 0, $amount),
            'reviewed_at' => null,
            'description' => __('Refunded at the gateway after we recorded it as failed'),
        ]);
    }

    /**
     * Take back from account credit what a provider refund or dispute reversed beyond what the payments could return:
     * at most the credit these payments placed (overpayments, payments of closed invoices, and staff refunds of them
     * to credit) that no earlier reversal took back, so it never reaches credit from anywhere else. The balance may
     * go negative when the client already spent the credit; staff see that as "Clients owing account credit". Once
     * per provider refund or dispute id: the ledger line carries (gateway, id), unique in the database. A replay finds
     * it under the client lock and a race that slips past hits the index; either fails the caller's transaction,
     * answered "duplicate". Called with the payment and invoice locked, so locks run payment, invoice, client.
     *
     * @param  list<Transaction>  $parents
     * @return int the amount taken back (document currency minor units)
     */
    private function reverseCredit(string $gatewayKey, string $reversalId, array $parents, int $remaining, CreditReason $reason, string $description): int
    {
        $payment = $parents[0];
        Client::query()->whereKey($payment->client_id)->lockForUpdate()->firstOrFail();

        if (CreditLedgerEntry::query()->where('gateway', $gatewayKey)->where('external_ref', $reversalId)->exists()) {
            throw new ReversalAlreadyRecorded;
        }

        $ids = array_map(fn (Transaction $parent): int => $parent->id, $parents);
        $refunds = Transaction::query()->whereIn('parent_transaction_id', $ids)->where('type', TransactionType::Refund)
            ->where('status', TransactionStatus::Succeeded)->pluck('id')->all();

        $placed = (int) CreditLedgerEntry::query()->where('currency', $payment->currency)->where('amount', '>', 0)
            ->where(fn ($lines) => $lines->where(fn ($own) => $own->whereIn('transaction_id', $ids)->where('reason', CreditReason::Overpayment))
                ->orWhere(fn ($refunded) => $refunded->whereIn('transaction_id', $refunds)->where('reason', CreditReason::RefundToCredit)))
            ->sum('amount');
        // Net of every provider-tagged line on these payments: take-backs, and the restores of won disputes (#191), so
        // credit a win gave back can be taken back again by a later refund or dispute.
        $reversed = -(int) CreditLedgerEntry::query()->where('currency', $payment->currency)->whereIn('transaction_id', $ids)
            ->whereNotNull('gateway')->sum('amount');
        $taken = min($remaining, max(0, $placed - $reversed - Transaction::creditPaidOut($ids, $payment->currency)));

        if ($taken > 0) {
            $this->credit->post(
                $payment->client_id,
                Money::of($taken, $payment->currency)->negate(),
                $reason,
                $payment->invoice_id,
                $payment->id,
                mb_substr($description, 0, 255),
                allowNegative: true,
                gateway: $gatewayKey,
                externalRef: $reversalId,
            );
        }

        return $taken;
    }

    /**
     * The payment(s) a provider charge id refers to: one transaction, or the allocations of a combined charge.
     *
     * @return list<Transaction>
     */
    private function parents(string $gatewayKey, CallbackEvent $event): array
    {
        if ($event->parentTxnId === null) {
            return [];
        }

        $single = Transaction::query()->where('gateway', $gatewayKey)->where('gateway_txn_id', $event->parentTxnId)->where('type', TransactionType::Payment)->first();

        if ($single !== null) {
            return [$single];
        }

        $batch = PaymentBatch::query()->where('gateway', $gatewayKey)->where('gateway_txn_id', $event->parentTxnId)->first();

        return $batch === null ? [] : array_values(Transaction::query()->where('payment_batch_id', $batch->id)
            ->where('type', TransactionType::Payment)->where('amount_in', '>', 0)->orderBy('id')->get()->all());
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
