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
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\GatewayAmounts;
use App\Modules\Billing\Services\GatewayLogger;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Support\AfterCommit;
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
                throw new BillingException(__('The refund can be at most :amount.', ['amount' => Money::of($payment->refundable_remaining, $payment->currency)->format()]));
            }

            if ($mode === RefundMode::Gateway) {
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
            $result = $this->refundAtGateway($payment->fresh() ?? $payment, $amount);

            if (! $result->succeeded()) {
                DB::transaction(function () use ($refund, $payment, $amount): void {
                    $refund->update(['status' => TransactionStatus::Failed, 'refundable_remaining' => 0]);
                    Transaction::query()->whereKey($payment->id)->lockForUpdate()->first()?->increment('refundable_remaining', $amount);
                });

                throw new BillingException(__('The gateway rejected the refund (:reason).', ['reason' => $result->declineReason ?? $result->status]));
            }

            $refund->forceFill(['gateway_txn_id' => $result->txnId, 'raw' => GatewayLogger::redact($result->raw)]);
        }

        // 3. Finalise the books.
        return DB::transaction(function () use ($refund, $payment, $amount, $mode, $reverseEffects, $userId): Transaction {
            $refund->status = TransactionStatus::Succeeded;
            $refund->save();

            if ($mode === RefundMode::ToCredit) {
                $this->credit->post($payment->client_id, Money::of($amount, $payment->currency), CreditReason::RefundToCredit, $payment->invoice_id, $refund->id, __('Refund to credit'), $userId);
            }

            $invoice = $payment->invoice_id !== null ? Invoice::query()->lockForUpdate()->find($payment->invoice_id) : null;

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
    private function refundAtGateway(Transaction $payment, int $amount): ChargeResult
    {
        $charge = $this->amounts->charge($payment);
        $result = $this->gateways->driver($payment->gateway)->refund($this->gateways->config($payment->gateway), new RefundRequest(
            providerTxnId: (string) $charge['txnId'],
            amount: $this->amounts->toGateway($payment, $amount),
            originalAmount: Money::of($charge['gatewayAmount'], $charge['gatewayCurrency']),
            paymentRaw: $payment->raw ?? [],
        ));
        $this->log->log($payment->gateway, 'response', 'refund '.$result->status, $result->raw, $payment->invoice_id);

        return $result;
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

        AfterCommit::dispatch(new InvoiceRefunded($invoice->id, $invoice->client_id, $amount, $invoice->currency, $invoice->status === InvoiceStatus::Refunded, $reverseEffects));

        if ($reverseEffects) {
            AfterCommit::dispatch(new PaymentReversed($invoice->id, $invoice->client_id, $refund->id, $amount, $invoice->currency, 'refund'));
        }
    }
}
