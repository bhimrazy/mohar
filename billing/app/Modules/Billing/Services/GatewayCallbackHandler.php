<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\GatewayDriver;
use App\Data\Billing\CallbackEvent;
use App\Data\Billing\CallbackRejection;
use App\Events\Billing\PaymentFailed;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Actions\RecordChargeback;
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
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\PaymentBatch;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Support\Money;
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

        $event = $driver->parseCallback($this->gateways->config($gateway), $request);

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
                CallbackEvent::CHARGEBACK => $this->reversed($gatewayKey, $event),
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
        if ($this->refund->existing($gatewayKey, $event->txnId) !== null) {
            return $this->outcome($gatewayKey, 200, 'duplicate', $event->raw, $event->invoiceId);
        }

        // A refund started from our admin names its refund row. Only a row that really recorded it ends here: one
        // still in flight is retried later, and one marked failed (no answer from the provider, yet the refund
        // happened) is booked now.
        $own = $event->reference !== null && ctype_digit($event->reference)
            ? Transaction::query()->whereKey((int) $event->reference)->where('gateway', $gatewayKey)->where('type', TransactionType::Refund)->first()
            : null;

        if ($own?->status === TransactionStatus::Succeeded) {
            return $this->outcome($gatewayKey, 200, 'refund already recorded', $event->raw, $own->invoice_id);
        }

        if ($own?->status === TransactionStatus::Pending) {
            return $this->outcome($gatewayKey, 409, 'refund still being recorded; retry expected', $event->raw, $own->invoice_id);
        }

        $parents = $this->parents($gatewayKey, $event);

        if ($parents === []) {
            // Out of order (refund before payment): reject so the provider retries later.
            return $this->outcome($gatewayKey, 409, self::PAYMENT_NOT_BOOKED, $event->raw, $event->invoiceId);
        }

        $remaining = $this->amounts->toDocument($parents[0], $event->amount, $event->currency);
        $txnId = $event->txnId;

        foreach ($parents as $parent) {
            $portion = min($remaining, $parent->refundable_remaining);

            if ($portion <= 0) {
                continue;
            }

            $this->refund->handle($parent, $portion, RefundMode::RecordOnly, gatewayTxnId: $txnId, reason: __('Refunded at the gateway'));
            $remaining -= $portion;
            $txnId = null;
        }

        if ($remaining > 0 && ($flagged = $this->flaggedAttempt($parents)) !== null) {
            return $this->takeBackCredit($gatewayKey, $event, $flagged, $parents, $remaining);
        }

        if ($remaining > 0) {
            // E.g. a refund of money that went to account credit: acknowledge (no retries) and leave it to staff.
            return $this->outcome($gatewayKey, 200, 'refund needs staff review', [...$event->raw, 'unallocated' => $remaining], $parents[0]->invoice_id);
        }

        return $this->outcome($gatewayKey, 200, 'refund recorded', $event->raw, $parents[0]->invoice_id);
    }

    /**
     * The hosted attempt behind one of these payments, when it was flagged for staff (late, or paid twice): its
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
     * Debit the refunded amount the payments could not absorb from the client's credit, up to what is left there.
     * Anything the credit can't cover goes to staff ("Gateway payments to review"), so the client never keeps both
     * the refund and the credit. Once per provider refund id (the ledger line names it).
     *
     * @param  list<Transaction>  $parents
     * @return Outcome
     */
    private function takeBackCredit(string $gatewayKey, CallbackEvent $event, PaymentAttempt $attempt, array $parents, int $remaining): array
    {
        $payment = collect($parents)->firstWhere('id', $attempt->transaction_id) ?? $parents[0];
        $description = mb_substr(__('Refunded at the gateway (:refund)', ['refund' => $event->txnId]), 0, 255);

        [$taken, $uncovered, $duplicate] = DB::transaction(function () use ($payment, $attempt, $description, $remaining): array {
            Client::query()->whereKey($payment->client_id)->lockForUpdate()->firstOrFail();

            if (CreditLedgerEntry::query()->where('transaction_id', $payment->id)->where('description', $description)->exists()) {
                return [0, 0, true];
            }

            $taken = min($remaining, max(0, $this->credit->balance($payment->client_id, $payment->currency)->amount));

            if ($taken > 0) {
                $this->credit->post($payment->client_id, Money::of($taken, $payment->currency)->negate(), CreditReason::CreditRemoved, $payment->invoice_id, $payment->id, $description);
            }

            if ($remaining > $taken) {
                $attempt->update([
                    'check_reason' => PaymentAttempt::CHECK_REFUND,
                    'late_checked_at' => null,
                    'review_reason' => mb_substr('Refunded at the provider for '.($remaining - $taken).' more than the account credit left', 0, 191),
                ]);
            }

            return [$taken, $remaining - $taken, false];
        });

        $payload = [...$event->raw, 'credit_taken_back' => $taken, 'unallocated' => $uncovered];

        return match (true) {
            $duplicate => $this->outcome($gatewayKey, 200, 'duplicate', $event->raw, $payment->invoice_id),
            $uncovered > 0 => $this->outcome($gatewayKey, 200, 'refund needs staff review', $payload, $payment->invoice_id),
            default => $this->outcome($gatewayKey, 200, 'refund recorded; credit taken back', $payload, $payment->invoice_id),
        };
    }

    /**
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

        $remaining = $this->amounts->toDocument($parents[0], $event->amount, $event->currency);

        foreach ($parents as $index => $parent) {
            $portion = min($remaining, $parent->refundable_remaining);

            if ($portion <= 0 && $index > 0) {
                continue;
            }

            // Only the first row carries the provider's dispute id (unique); further allocations reference the batch.
            $this->chargeback->handle($parent, $portion, $index === 0 ? $event->txnId : null, GatewayLogger::redact($event->raw));
            $remaining -= $portion;
        }

        return $this->outcome($gatewayKey, 200, 'chargeback recorded', $event->raw, $parents[0]->invoice_id);
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
