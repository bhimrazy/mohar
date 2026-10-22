<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Billing\LooksUpRefunds;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\RefundRequest;
use App\Events\Billing\InvoiceRefunded;
use App\Events\Billing\PaymentReversed;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\GatewayAmounts;
use App\Modules\Billing\Services\GatewayLogger;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Support\AfterCommit;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Refund (part of) a payment (§4.7): through the gateway, record-only, or to account credit.
 * amount ≤ refundable_remaining; paid invoices get a refund credit note and become refunded when nothing is left.
 */
class RefundTransaction
{
    public function __construct(
        private GatewayManager $gateways,
        private InvoiceSettlement $settlement,
        private CreditLedgerService $credit,
        private GatewayLogger $log,
        private GatewayAmounts $amounts,
    ) {}

    public function handle(
        Transaction $payment,
        int $amount,
        RefundMode $mode,
        bool $reverseEffects = false,
        ?int $userId = null,
        ?string $gatewayTxnId = null,
        ?string $reason = null,
    ): Transaction {
        if ($gatewayTxnId !== null && $existing = $this->existing($payment->gateway, $gatewayTxnId)) {
            return $existing;
        }

        // 1. Reserve: validate under lock and record the refund as pending (refundable amount held back).
        $refund = DB::transaction(function () use ($payment, $amount, $mode, $reverseEffects, $userId, $gatewayTxnId, $reason): Transaction {
            $payment = Transaction::query()->lockForUpdate()->findOrFail($payment->id);

            if (! $payment->isRefundable()) {
                throw new BillingException(__('This transaction has nothing left to refund.'));
            }

            if ($amount <= 0 || $amount > $payment->refundable_remaining) {
                throw new BillingException(__('The refund can be at most :amount.', ['amount' => app(MoneyFormatter::class)->format(Money::of($payment->refundable_remaining, $payment->currency))]));
            }

            if ($mode === RefundMode::Gateway) {
                $this->assertNoRefundInFlight($payment);
                $this->assertGatewayCanRefund($payment, $amount);
            }

            $payment->refundable_remaining -= $amount;
            $payment->save();

            return Transaction::query()->create([
                'client_id' => $payment->client_id,
                'invoice_id' => $payment->invoice_id,
                'payment_batch_id' => $payment->payment_batch_id,
                'gateway' => $payment->gateway,
                'gateway_txn_id' => $gatewayTxnId,
                'type' => TransactionType::Refund,
                'status' => $mode === RefundMode::Gateway ? TransactionStatus::Pending : TransactionStatus::Succeeded,
                'amount_out' => $amount,
                'currency' => $payment->currency,
                'parent_transaction_id' => $payment->id,
                'description' => $reason ?? match ($mode) {
                    RefundMode::Gateway => __('Refunded through gateway'),
                    RefundMode::RecordOnly => __('Refund recorded'),
                    RefundMode::ToCredit => __('Refunded to account credit'),
                },
                // A gateway refund carries what a settlement needs from its birth, so a worker that dies during the
                // provider call leaves a row that the callback, Check refund or staff can still settle.
                'raw' => $mode === RefundMode::Gateway ? [Transaction::REFUND_STATE => ['reverse_effects' => $reverseEffects, 'since' => now()->toIso8601String()]] : null,
                'created_by' => $userId,
                'occurred_at' => now(),
            ]);
        });

        // 2. Call the provider outside any DB transaction (no locks held over network I/O; the log always survives).
        if ($mode === RefundMode::Gateway) {
            try {
                $result = $this->refundAtGateway($payment->fresh() ?? $payment, $amount, $refund);
            } catch (Throwable $exception) {
                // Whether the request left is unknown: the same as a timeout.
                report($exception);
                $result = new ChargeResult(ChargeResult::ERROR, declineReason: 'the refund call failed before an answer arrived');
            }

            if (! $result->succeeded()) {
                return $this->unsettled($refund, $result);
            }
        }

        return $this->finalise($refund, $payment, $amount, $mode, $reverseEffects, $userId, $result ?? null);
    }

    /**
     * Settle a pending refund the provider has confirmed: its refund callback, a status check, or staff. It is the
     * same row and reference, so the provider's idempotency key never changes. Acts only while the row is still
     * pending, under its lock. A provider refund id already booked on another row is the same refund: this row is
     * released and that one returned, so one provider refund is booked once.
     *
     * @param  array<string, mixed>  $raw
     */
    public function settle(Transaction $refund, ?string $providerRefundId, array $raw = []): Transaction
    {
        $payment = Transaction::query()->findOrFail($refund->parent_transaction_id);
        $result = new ChargeResult(ChargeResult::SUCCESS, $providerRefundId, raw: $raw);

        return $this->finalise($refund, $payment, $refund->amount_out, RefundMode::Gateway, false, $refund->created_by, $result);
    }

    /**
     * Re-ask the provider about a pending refund with the same reference it was sent with. Only the provider's word
     * moves the row: SUCCESS settles it, a definite "no such refund" releases the amount, anything else leaves it.
     */
    public function check(Transaction $refund): Transaction
    {
        $payment = $this->resolvable($refund);
        $driver = $this->gateways->driver($refund->gateway);

        if (! $driver instanceof LooksUpRefunds) {
            throw new BillingException(__(':gateway can\'t report on a refund. Confirm or release it by hand once you have checked with the provider.', ['gateway' => $driver->name()]));
        }

        $result = $driver->lookupRefund($this->gateways->config($refund->gateway), $this->providerRequest($payment, $refund->amount_out, $refund));
        $this->log->log($refund->gateway, 'response', 'refund lookup '.$result->status, $result->raw, $refund->invoice_id);

        if ($result->status === ChargeResult::SUCCESS) {
            return $this->settle($refund, $result->txnId, $result->raw);
        }

        if ($result->status === ChargeResult::DECLINED) {
            $this->release($refund);
        }

        return $refund->fresh() ?? $refund;
    }

    /**
     * Staff settle a pending refund by hand, for a provider that can't be asked: it was made there (with the
     * provider's own refund reference, booked and deduped on it) or it was not (released). Throws when something
     * settled the row first, so the caller never logs an outcome that did not happen.
     */
    public function resolve(Transaction $refund, bool $confirmed, ?string $providerReference = null): Transaction
    {
        $driver = $this->gateways->driver($refund->gateway);

        if ($driver instanceof LooksUpRefunds) {
            throw new BillingException(__(':gateway can be asked about this refund. Use Check refund.', ['gateway' => $driver->name()]));
        }

        $providerReference = $providerReference !== null ? trim($providerReference) : null;

        if ($confirmed && ($providerReference === null || $providerReference === '')) {
            throw new BillingException(__('Enter the provider\'s reference for this refund.'));
        }

        return DB::transaction(function () use ($refund, $confirmed, $providerReference): Transaction {
            $locked = Transaction::query()->lockForUpdate()->findOrFail($refund->id);
            $this->resolvable($locked);

            if (! $confirmed) {
                $this->release($locked);

                return $locked->fresh() ?? $locked;
            }

            $other = $this->existing($locked->gateway, (string) $providerReference);

            if ($other !== null && $other->id !== $locked->id) {
                throw new BillingException(__('That provider refund is already recorded.'));
            }

            return $this->settle($locked, $providerReference, ['resolved_by_staff' => true]);
        });
    }

    /**
     * A pending refund that can be checked or settled by hand: the provider's answer is unknown, or the request
     * has had longer than any call lasts. Returns its payment.
     */
    private function resolvable(Transaction $refund): Transaction
    {
        $fresh = $refund->fresh();

        if ($fresh === null || ! $fresh->canBeResolved()) {
            throw new BillingException(__('This refund is not waiting for the provider, or its request may still be running.'));
        }

        return Transaction::query()->findOrFail($fresh->parent_transaction_id);
    }

    /**
     * Release a pending refund the provider definitely did not make: the row fails and its amount is refundable
     * again, but never more than the payment can still give back after chargebacks. Acts only while the row is still
     * pending, under its lock; returns false when something settled it first.
     */
    public function release(Transaction $refund): bool
    {
        return DB::transaction(function () use ($refund): bool {
            $locked = Transaction::query()->lockForUpdate()->find($refund->id);

            return $locked !== null && $locked->status === TransactionStatus::Pending && $this->releaseLocked($locked);
        });
    }

    /** Called with the pending row locked. */
    private function releaseLocked(Transaction $refund): bool
    {
        $payment = Transaction::query()->whereKey($refund->parent_transaction_id)->lockForUpdate()->first();
        $refund->update(['status' => TransactionStatus::Failed, 'refundable_remaining' => 0]);

        if ($payment !== null) {
            $others = (int) Transaction::query()->where('parent_transaction_id', $payment->id)->whereKeyNot($refund->id)
                ->whereIn('type', [TransactionType::Refund, TransactionType::Chargeback])->whereIn('status', [TransactionStatus::Succeeded, TransactionStatus::Pending])->sum('amount_out');
            // A chargeback's unabsorbed part (review_amount) was taken from this payment too, though its row is capped.
            $others += (int) Transaction::query()->where('parent_transaction_id', $payment->id)->where('type', TransactionType::Chargeback)
                ->where('status', TransactionStatus::Succeeded)->sum('review_amount');
            $won = (int) Transaction::query()->where('parent_transaction_id', $payment->id)->where('type', TransactionType::ChargebackReversal)
                ->where('status', TransactionStatus::Succeeded)->sum('amount_in');
            $payment->update(['refundable_remaining' => min($payment->refundable_remaining + $refund->amount_out, max(0, $payment->amount_in - $others + $won))]);
        }

        return true;
    }

    /**
     * The provider did not confirm the refund. Only a definite decline releases the amount. Any other answer (a
     * timeout, an error, a pending state) may mean the refund happened, so the row stays pending with the amount
     * reserved, and the provider's callback, a check or staff settle it later. A retry would be a new refund.
     */
    private function unsettled(Transaction $refund, ChargeResult $result): Transaction
    {
        if ($result->status === ChargeResult::DECLINED) {
            if ($this->release($refund)) {
                throw new BillingException(__('The gateway rejected the refund (:reason).', ['reason' => $result->declineReason ?? $result->status]));
            }

            return $refund->fresh() ?? $refund;
        }

        return DB::transaction(function () use ($refund, $result): Transaction {
            $locked = Transaction::query()->lockForUpdate()->findOrFail($refund->id);

            if ($locked->status === TransactionStatus::Pending) {
                $state = [...($locked->raw[Transaction::REFUND_STATE] ?? []), 'answer' => $result->status, 'reason' => $result->declineReason];
                $locked->forceFill([
                    'gateway_txn_id' => $locked->gateway_txn_id ?? $result->txnId,
                    'raw' => [...GatewayLogger::redact($result->raw), Transaction::REFUND_STATE => $state],
                ])->save();
            }

            return $locked;
        });
    }

    /**
     * Finalise the books. Locks in the module's order: invoice, then client (the credit post), as payments,
     * credit and chargebacks take them, so a refund to credit never deadlocks with a dispute of the same invoice.
     * A gateway refund is booked only while its row is still pending (taken under lock first), so a late answer
     * never books a refund the callback already settled; it reads its reverse-effects choice from the row.
     */
    private function finalise(Transaction $refund, Transaction $payment, int $amount, RefundMode $mode, bool $reverseEffects, ?int $userId, ?ChargeResult $result): Transaction
    {
        return DB::transaction(function () use ($refund, $payment, $amount, $mode, $reverseEffects, $userId, $result): Transaction {
            if ($mode === RefundMode::Gateway) {
                $refund = Transaction::query()->lockForUpdate()->findOrFail($refund->id);

                if ($refund->status !== TransactionStatus::Pending) {
                    return $refund;
                }

                // The provider's refund id books once: a row already holding it is this refund, and this one is released.
                $other = $result?->txnId !== null && $result->txnId !== '' ? $this->existing($refund->gateway, $result->txnId) : null;

                if ($other !== null && $other->id !== $refund->id) {
                    $this->releaseLocked($refund);

                    return $other;
                }

                $state = $refund->raw[Transaction::REFUND_STATE] ?? [];
                $reverseEffects = (bool) ($state['reverse_effects'] ?? $reverseEffects);
                $refund->forceFill([
                    'gateway_txn_id' => $result->txnId ?? $refund->gateway_txn_id,
                    'raw' => GatewayLogger::redact([...($refund->raw ?? []), ...($result->raw ?? []), Transaction::REFUND_STATE => $state]),
                ]);
            }

            $invoice = $payment->invoice_id !== null ? Invoice::query()->lockForUpdate()->find($payment->invoice_id) : null;

            $refund->status = TransactionStatus::Succeeded;
            $refund->save();

            if ($mode === RefundMode::ToCredit) {
                $this->credit->post($payment->client_id, Money::of($amount, $payment->currency), CreditReason::RefundToCredit, $payment->invoice_id, $refund->id, __('Refund to credit'), $userId);
            }

            if ($invoice !== null) {
                $this->applyToInvoice($invoice, $refund, $amount, $reverseEffects, $userId);
            }

            return $refund;
        });
    }

    public function existing(string $gateway, string $gatewayTxnId): ?Transaction
    {
        return Transaction::query()->where('gateway', $gateway)->where('gateway_txn_id', $gatewayTxnId)->where('type', TransactionType::Refund)->first();
    }

    /**
     * One provider refund per payment at a time: while one is pending (in flight, or unconfirmed after a timeout) a
     * second call could refund the same money again. Called with the payment locked.
     */
    private function assertNoRefundInFlight(Transaction $payment): void
    {
        $pending = Transaction::query()->where('parent_transaction_id', $payment->id)->where('type', TransactionType::Refund)
            ->where('status', TransactionStatus::Pending)->first();

        if ($pending !== null) {
            throw new BillingException(__('A refund of :amount is being confirmed with the provider. Wait for it to settle before refunding this payment again.', [
                'amount' => app(MoneyFormatter::class)->format(Money::of($pending->amount_out, $pending->currency)),
            ]));
        }
    }

    private function assertGatewayCanRefund(Transaction $payment, int $amount): void
    {
        $driver = $this->gateways->driver($payment->gateway);

        if (! $driver->supports(GatewayDriver::REFUND)) {
            throw new BillingException(__(':gateway can\'t refund automatically. Record the refund instead.', ['gateway' => $driver->name()]));
        }

        $charge = $this->amounts->charge($payment);

        if ($charge['txnId'] === null) {
            throw new BillingException(__('This payment has no gateway reference. Record the refund instead.'));
        }

        if ($amount < $charge['documentAmount'] && ! $driver->supports(GatewayDriver::PARTIAL_REFUND)) {
            throw new BillingException(__(':gateway only refunds the full amount automatically. Record partial refunds instead.', ['gateway' => $driver->name()]));
        }
    }

    /**
     * The refund is sent in what the provider charged (gateway currency, prorated from the recorded amounts). The
     * pending refund row is created first, so its id identifies this refund at the provider (idempotency key,
     * reconciliation of a refund whose answer never arrived).
     */
    private function refundAtGateway(Transaction $payment, int $amount, Transaction $refund): ChargeResult
    {
        $result = $this->gateways->driver($payment->gateway)->refund($this->gateways->config($payment->gateway), $this->providerRequest($payment, $amount, $refund));
        $this->log->log($payment->gateway, 'response', 'refund '.$result->status, $result->raw, $payment->invoice_id);

        return $result;
    }

    /**
     * The request a refund row is sent as, always built the same way: a status check or a retry of the same row asks
     * the provider about the same reference.
     */
    private function providerRequest(Transaction $payment, int $amount, Transaction $refund): RefundRequest
    {
        $charge = $this->amounts->charge($payment);

        return new RefundRequest(
            providerTxnId: (string) $charge['txnId'],
            amount: $this->amounts->toGateway($payment, $amount),
            originalAmount: Money::of($charge['gatewayAmount'], $charge['gatewayCurrency']),
            paymentRaw: $payment->raw ?? [],
            reference: (string) $refund->id,
            sentAt: $refund->created_at !== null ? CarbonImmutable::instance($refund->created_at) : null,
        );
    }

    private function applyToInvoice(Invoice $invoice, Transaction $refund, int $amount, bool $reverseEffects, ?int $userId): void
    {
        $wasPaid = in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Refunded], true);
        $invoice->amount_paid -= $amount;

        if ($wasPaid) {
            $this->settlement->adjust($invoice, AdjustmentKind::Credit, AdjustmentReason::Refund, $amount, __('Refund'), $refund->id, $userId);

            if ($invoice->amount_paid <= 0) {
                $invoice->status = InvoiceStatus::Refunded;
            }
        } else {
            $invoice->recalculateBalance();
        }

        $invoice->save();

        if ($invoice->kind === InvoiceKind::AddFunds) {
            $this->takeBackDeposit($invoice, $refund, $amount, $userId);
        }

        AfterCommit::dispatch(new InvoiceRefunded($invoice->id, $invoice->client_id, $amount, $invoice->currency, $invoice->status === InvoiceStatus::Refunded, $reverseEffects));

        if ($reverseEffects) {
            AfterCommit::dispatch(new PaymentReversed($invoice->id, $invoice->client_id, $refund->id, $amount, $invoice->currency, 'refund'));
        }
    }

    /**
     * A refunded deposit takes back the account credit it placed (#223), as a chargeback of it does: the refunded
     * amount, at most what the deposit still holds on this invoice (its add-funds line, less the chargebacks, refunds
     * and won disputes booked against it without a provider tag), so a refund in parts never takes back more than was
     * deposited. The balance may go negative when the client already spent the credit ("Clients owing account
     * credit"). Called with the invoice locked; the post locks the client, so locks run invoice, then client.
     */
    private function takeBackDeposit(Invoice $invoice, Transaction $refund, int $amount, ?int $userId): void
    {
        $held = (int) CreditLedgerEntry::query()->where('client_id', $invoice->client_id)->where('invoice_id', $invoice->id)
            ->where('currency', $invoice->currency)->whereNull('gateway')
            ->whereIn('reason', [CreditReason::AddFunds, CreditReason::Reversal])->sum('amount');
        $taken = min($amount, max(0, $held));

        if ($taken > 0) {
            $this->credit->post($invoice->client_id, Money::of(-$taken, $invoice->currency), CreditReason::Reversal, $invoice->id, $refund->id,
                __('Deposit reversed (refund)'), $userId, allowNegative: true);
        }
    }
}
