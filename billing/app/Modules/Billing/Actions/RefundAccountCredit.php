<?php

namespace App\Modules\Billing\Actions;

use App\Data\Billing\ChargeResult;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\GatewayLogger;
use App\Modules\Core\Models\Client;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pay account credit out to the client (ADR 0024 §5a). It is not a supply: no note, no invoice changes, in or out of Nepal
 * VAT mode. Recorded (made outside), or through the gateway against an earlier payment of the client.
 *
 * The refund row has `purpose = credit_refund` and no invoice. A `credit_refunded` ledger row of −amount is posted
 * before the provider is called, so the credit cannot be spent meanwhile; it carries the refund row's id, and a decline
 * reverses it with `credit_refund_released`. Callbacks, Check refund and staff reach a pending row through
 * `RefundTransaction`, which hands every `credit_refund` row to `settle()` and `returnPayout()` here.
 * Settle and release lock the refund row, then the payment and the client; reserving locks the payment and then the client (the row does not exist yet).
 */
class RefundAccountCredit
{
    public function __construct(
        private RefundTransaction $refunds,
        private CreditLedgerService $credit,
    ) {}

    /** A refund recorded as made outside: one transaction, the client is the only lock. */
    public function recorded(Client $client, int $amount, string $method, string $reference, ?string $note, ?int $userId): Transaction
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw new BillingException(__('Enter the reference of the payout.'));
        }

        try {
            return DB::transaction(function () use ($client, $amount, $method, $reference, $note, $userId): Transaction {
                $client = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
                $refund = $this->row($client, $amount, $method, TransactionStatus::Succeeded, $reference, $note, $userId, null);
                $this->post($client, -$amount, CreditReason::CreditRefunded, $refund, 'credit-refund:'.$refund->id, $userId);

                return $refund;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BillingException(__('That payout reference is already recorded.'));
        }
    }

    /** A refund through the gateway of an earlier payment of the client. */
    public function throughGateway(Client $client, Transaction $payment, int $amount, ?string $note, ?int $userId): Transaction
    {
        $refund = DB::transaction(function () use ($client, $payment, $amount, $note, $userId): Transaction {
            $payment = Transaction::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->client_id !== $client->id || ! $payment->isRefundable()) {
                throw new BillingException(__('This payment has nothing left to refund.'));
            }

            if ($amount <= 0 || $amount > $payment->refundable_remaining) {
                throw new BillingException(__('The refund can be at most :amount.', ['amount' => app(MoneyFormatter::class)->format(Money::of($payment->refundable_remaining, $payment->currency))]));
            }

            $this->refunds->assertNoRefundInFlight($payment);
            $this->refunds->assertGatewayCanRefund($payment, $amount);

            $client = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();

            if ($payment->currency !== $client->currency) {
                throw new BillingException(__('Account credit must be in the client currency (:currency).', ['currency' => $client->currency]));
            }

            $refund = $this->row($client, $amount, $payment->gateway, TransactionStatus::Pending, null, $note, $userId, $payment);
            $payment->refundable_remaining -= $amount;
            $payment->save();
            $this->post($client, -$amount, CreditReason::CreditRefunded, $refund, 'credit-refund:'.$refund->id, $userId, $payment->gateway);

            return $refund;
        });

        try {
            $result = $this->refunds->refundAtGateway($payment->fresh() ?? $payment, $amount, $refund);
        } catch (Throwable $exception) {
            // Whether the request left is unknown: the same as a timeout.
            report($exception);
            $result = new ChargeResult(ChargeResult::ERROR, declineReason: 'the refund call failed before an answer arrived');
        }

        return $result->succeeded() ? $this->settle($refund, $result->txnId, $result->raw) : $this->refunds->unsettled($refund, $result);
    }

    /**
     * The provider confirmed the refund. A pending row is marked succeeded and its payout stands. A row already marked
     * failed (declined or released, yet the provider paid) is paid after a decline: the payout is posted again, the
     * payment's refundable amount is taken back as far as it still goes, and staff get a to-do. Booked once, on the
     * provider's refund id. A settled row is left alone.
     *
     * @param  array<string, mixed>  $raw
     */
    public function settle(Transaction $refund, ?string $providerRefundId, array $raw = []): Transaction
    {
        return DB::transaction(function () use ($refund, $providerRefundId, $raw): Transaction {
            // The refund row first, then payment and client: the order release and the invoice path's finalise take.
            $locked = Transaction::query()->lockForUpdate()->findOrFail($refund->id);
            $payment = Transaction::query()->lockForUpdate()->find($locked->parent_transaction_id);
            $client = Client::query()->whereKey($locked->client_id)->lockForUpdate()->firstOrFail();

            if ($locked->status === TransactionStatus::Succeeded) {
                return $locked;
            }

            $providerRefundId = $providerRefundId !== null && $providerRefundId !== '' ? $providerRefundId : null;
            $other = $providerRefundId !== null ? $this->refunds->existing($locked->gateway, $providerRefundId) : null;

            if ($other !== null && $other->id !== $locked->id) {
                // The same provider refund is booked on another row: this row is not a second payout.
                if ($locked->status === TransactionStatus::Pending) {
                    $this->refunds->release($locked);
                }

                return $other;
            }

            $state = $locked->raw[Transaction::REFUND_STATE] ?? [];
            $late = $locked->status === TransactionStatus::Failed;
            $locked->forceFill([
                'status' => TransactionStatus::Succeeded,
                'gateway_txn_id' => $providerRefundId ?? $locked->gateway_txn_id,
                'raw' => GatewayLogger::redact([...($locked->raw ?? []), ...$raw, Transaction::REFUND_STATE => $state]),
            ]);

            if ($late) {
                $taken = $payment !== null ? min($locked->amount_out, max(0, $payment->refundable_remaining)) : 0;
                $payment?->forceFill(['refundable_remaining' => $payment->refundable_remaining - $taken])->save();
                $this->post($client, -$locked->amount_out, CreditReason::CreditRefunded, $locked, 'credit-refund-late:'.$locked->id, $locked->created_by, $locked->gateway, allowNegative: true);
                $locked->forceFill([
                    'review_amount' => $locked->amount_out,
                    'reviewed_at' => null,
                    'description' => __('Credit refund paid after a decline'),
                    'raw' => [...$locked->raw, Transaction::REFUND_STATE => [...$state, 'late' => ['refundable_short' => $locked->amount_out - $taken]]],
                ]);
            }

            $locked->save();

            return $locked;
        });
    }

    /**
     * The provider paid a credit refund we cannot book from its callback (a different amount, or no refund id) after the row was marked
     * failed: its payout is back in the balance, so put the row on Disputes to review. Returns whether it was put there. A row that is
     * pending is already on the waiting list, and a settled one needs nothing.
     */
    public function flagLateForReview(Transaction $refund, string $why): bool
    {
        return DB::transaction(function () use ($refund, $why): bool {
            $locked = Transaction::query()->lockForUpdate()->find($refund->id);

            if ($locked === null || $locked->status !== TransactionStatus::Failed) {
                return false;
            }

            $locked->forceFill(['review_amount' => max($locked->review_amount ?? 0, $locked->amount_out), 'reviewed_at' => null, 'description' => mb_substr((string) __('Credit refund reported paid by the provider after a decline (:why)', ['why' => $why]), 0, 255)])->save();

            return true;
        });
    }

    /** The provider did not pay: the payout is reversed, so the credit is spendable again. Called with the pending row locked. */
    public function returnPayout(Transaction $refund): void
    {
        $this->post(Client::query()->findOrFail($refund->client_id), $refund->amount_out, CreditReason::CreditRefundReleased, $refund, 'credit-refund-released:'.$refund->id, $refund->created_by, $refund->gateway);
    }

    private function row(Client $client, int $amount, string $gateway, TransactionStatus $status, ?string $reference, ?string $note, ?int $userId, ?Transaction $payment): Transaction
    {
        if ($amount <= 0) {
            throw new BillingException(__('Enter an amount above zero.'));
        }

        return Transaction::query()->create([
            'client_id' => $client->id,
            'invoice_id' => null,
            'gateway' => $gateway,
            'gateway_txn_id' => $reference,
            'type' => TransactionType::Refund,
            'status' => $status,
            'amount_out' => $amount,
            'currency' => $client->currency,
            'parent_transaction_id' => $payment?->id,
            'purpose' => Transaction::PURPOSE_CREDIT_REFUND,
            'description' => $note ?? __('Account credit refunded'),
            // A gateway refund carries what a settlement needs from its birth (the same as RefundTransaction).
            'raw' => $payment !== null ? [Transaction::REFUND_STATE => ['reverse_effects' => false, 'since' => now()->toIso8601String()]] : null,
            'created_by' => $userId,
            'occurred_at' => now(),
        ]);
    }

    /** A signed movement tied to the refund row; the balance check (recorded and reserve) is the ledger's. */
    private function post(Client $client, int $signed, CreditReason $reason, Transaction $refund, string $externalRef, ?int $userId, ?string $gateway = null, bool $allowNegative = false): void
    {
        $paidOut = $reason !== CreditReason::CreditRefundReleased;

        $this->credit->post($client->id, Money::of($signed, $client->currency), $reason, null, $refund->id,
            mb_substr((string) ($paidOut ? __('Account credit refunded (:refund)', ['refund' => $refund->id]) : __('Credit refund :refund not made: credit returned', ['refund' => $refund->id])), 0, 255),
            $userId, allowNegative: $allowNegative, gateway: $gateway, externalRef: $gateway !== null ? $externalRef : null);
    }
}
