<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Billing\GatewayDriver;
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
use Illuminate\Support\Facades\DB;

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
        $refund = DB::transaction(function () use ($payment, $amount, $mode, $userId, $gatewayTxnId, $reason): Transaction {
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
                'created_by' => $userId,
                'occurred_at' => now(),
            ]);
        });

        // 2. Call the provider outside any DB transaction (no locks held over network I/O; the log always survives).
        if ($mode === RefundMode::Gateway) {
            $result = $this->refundAtGateway($payment->fresh() ?? $payment, $amount, $refund);

            if (! $result->succeeded()) {
                return $this->unsettled($refund, $result, $reverseEffects);
            }
        }

        return $this->finalise($refund, $payment, $amount, $mode, $reverseEffects, $userId, $result ?? null);
    }

    /**
     * Settle a refund that was left pending (the provider's answer never came): the provider's refund callback, a
     * status check, or staff have confirmed it. Same row, same reference, so the provider's idempotency key never
     * changes. Acts only while the row is still pending, under its lock. A provider refund id already booked on
     * another row is the same refund: this row is released instead, so one provider refund is booked once.
     *
     * @param  array<string, mixed>  $raw
     */
    public function settle(Transaction $refund, ?string $providerRefundId, array $raw = []): Transaction
    {
        if ($providerRefundId !== null && $providerRefundId !== '' && ($other = $this->existing($refund->gateway, $providerRefundId)) !== null && $other->id !== $refund->id) {
            $this->release($refund);

            return $other;
        }

        $payment = Transaction::query()->findOrFail($refund->parent_transaction_id);
        $result = new ChargeResult(ChargeResult::SUCCESS, $providerRefundId, raw: $raw);
        $reverse = (bool) ($refund->raw['confirmation']['reverse_effects'] ?? false);

        return $this->finalise($refund, $payment, $refund->amount_out, RefundMode::Gateway, $reverse, $refund->created_by, $result);
    }

    /**
     * Re-ask the provider about a pending refund (same reference). Only the provider's word moves the row: SUCCESS
     * settles it, a definite "no such refund" releases the amount, anything else leaves it pending.
     */
    public function check(Transaction $refund): Transaction
    {
        $payment = $this->pendingParent($refund);
        $driver = $this->gateways->driver($refund->gateway);

        if (! $driver->supports(GatewayDriver::REFUND_LOOKUP)) {
            throw new BillingException(__(':gateway can\'t report on a refund. Confirm or reject it by hand once you have checked with the provider.', ['gateway' => $driver->name()]));
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
     * Staff resolve a pending refund by hand, for a provider with no status check: it was made at the provider
     * (booked now, deduped later by the provider's refund id when the callback names it) or it was not (released).
     */
    public function resolve(Transaction $refund, bool $confirmed): Transaction
    {
        $this->pendingParent($refund);

        if ($confirmed) {
            return $this->settle($refund, null, ['resolved_by_staff' => true]);
        }

        $this->release($refund);

        return $refund->fresh() ?? $refund;
    }

    private function pendingParent(Transaction $refund): Transaction
    {
        $fresh = $refund->fresh();

        if ($fresh === null || ! $fresh->awaitsProviderConfirmation()) {
            throw new BillingException(__('This refund is not waiting for the provider any more.'));
        }

        return Transaction::query()->findOrFail($fresh->parent_transaction_id);
    }

    /**
     * Release a pending refund the provider definitely did not make: the row fails and its amount is refundable again.
     * Acts only while the row is still pending, under its lock; returns false when something settled it first.
     */
    public function release(Transaction $refund): bool
    {
        return DB::transaction(function () use ($refund): bool {
            $locked = Transaction::query()->lockForUpdate()->find($refund->id);

            if ($locked === null || $locked->status !== TransactionStatus::Pending) {
                return false;
            }

            $locked->update(['status' => TransactionStatus::Failed, 'refundable_remaining' => 0]);
            Transaction::query()->whereKey($locked->parent_transaction_id)->lockForUpdate()->first()?->increment('refundable_remaining', $locked->amount_out);

            return true;
        });
    }

    /**
     * The provider did not confirm the refund. Only a definite decline releases the amount. Any other answer (a
     * timeout, an error, a pending state) may mean the refund happened, so the row stays pending with the amount
     * reserved, and the provider's callback or a staff check settles it later. A retry would be a new refund.
     */
    private function unsettled(Transaction $refund, ChargeResult $result, bool $reverseEffects): Transaction
    {
        if ($result->status === ChargeResult::DECLINED) {
            if ($this->release($refund)) {
                throw new BillingException(__('The gateway rejected the refund (:reason).', ['reason' => $result->declineReason ?? $result->status]));
            }

            return $refund->fresh() ?? $refund;
        }

        return DB::transaction(function () use ($refund, $result, $reverseEffects): Transaction {
            $locked = Transaction::query()->lockForUpdate()->findOrFail($refund->id);

            if ($locked->status === TransactionStatus::Pending) {
                $locked->forceFill([
                    'gateway_txn_id' => $locked->gateway_txn_id ?? $result->txnId,
                    'raw' => [...GatewayLogger::redact($result->raw), 'confirmation' => [
                        'status' => $result->status,
                        'reason' => $result->declineReason,
                        'reverse_effects' => $reverseEffects,
                        'since' => now()->toIso8601String(),
                    ]],
                ])->save();
            }

            return $locked;
        });
    }

    /**
     * Finalise the books. Locks in the module's order: invoice, then client (the credit post), as payments,
     * credit and chargebacks take them, so a refund to credit never deadlocks with a dispute of the same invoice.
     * A gateway refund is booked only while its row is still pending (taken under lock first), so a late answer
     * never books a refund the callback already settled.
     */
    private function finalise(Transaction $refund, Transaction $payment, int $amount, RefundMode $mode, bool $reverseEffects, ?int $userId, ?ChargeResult $result): Transaction
    {
        return DB::transaction(function () use ($refund, $payment, $amount, $mode, $reverseEffects, $userId, $result): Transaction {
            if ($mode === RefundMode::Gateway) {
                $refund = Transaction::query()->lockForUpdate()->findOrFail($refund->id);

                if ($refund->status !== TransactionStatus::Pending) {
                    return $refund;
                }

                $refund->forceFill([
                    'gateway_txn_id' => $result->txnId ?? $refund->gateway_txn_id,
                    'raw' => GatewayLogger::redact([...($refund->raw ?? []), ...$result->raw]),
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
     * The refund is sent in what the provider charged (gateway currency, prorated from the recorded amounts).
     */
    /**
     * The pending refund row is created first, so its id identifies this refund at the provider (idempotency key,
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
