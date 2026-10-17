<?php

namespace App\Modules\Billing\Actions;

use App\Events\Billing\InvoiceRefunded;
use App\Modules\Billing\Drivers\Gateways\GatewayDriver;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Events\PaymentReversed;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
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
        return DB::transaction(function () use ($payment, $amount, $mode, $reverseEffects, $userId, $gatewayTxnId, $reason): Transaction {
            $payment = Transaction::query()->lockForUpdate()->findOrFail($payment->id);

            if ($gatewayTxnId !== null && $existing = Transaction::query()->where('gateway', $payment->gateway)->where('gateway_txn_id', $gatewayTxnId)->where('type', TransactionType::Refund)->first()) {
                return $existing;
            }

            if (! $payment->isRefundable()) {
                throw new BillingException(__('This transaction has nothing left to refund.'));
            }

            if ($amount <= 0 || $amount > $payment->refundable_remaining) {
                throw new BillingException(__('The refund can be at most :amount.', ['amount' => Money::of($payment->refundable_remaining, $payment->currency)->format()]));
            }

            $invoice = $payment->invoice_id !== null ? Invoice::query()->lockForUpdate()->find($payment->invoice_id) : null;

            if ($mode === RefundMode::Gateway) {
                $gatewayTxnId = $this->refundAtGateway($payment, $amount);
            }

            $refund = Transaction::query()->create([
                'client_id' => $payment->client_id,
                'invoice_id' => $payment->invoice_id,
                'gateway' => $payment->gateway,
                'gateway_txn_id' => $gatewayTxnId,
                'type' => TransactionType::Refund,
                'status' => TransactionStatus::Succeeded,
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

            $payment->refundable_remaining -= $amount;
            $payment->save();

            if ($mode === RefundMode::ToCredit) {
                $this->credit->post($payment->client_id, Money::of($amount, $payment->currency), CreditReason::RefundToCredit, $payment->invoice_id, $refund->id, __('Refund to credit'), $userId);
            }

            if ($invoice !== null) {
                $this->applyToInvoice($invoice, $refund, $amount, $reverseEffects, $userId);
            }

            return $refund;
        });
    }

    private function refundAtGateway(Transaction $payment, int $amount): ?string
    {
        $driver = $this->gateways->driver($payment->gateway);

        if (! $driver->supports(GatewayDriver::REFUND)) {
            throw new BillingException(__(':gateway can\'t refund automatically. Record the refund instead.', ['gateway' => $driver->name()]));
        }

        $result = $driver->refund($this->gateways->gateway($payment->gateway), $payment, Money::of($amount, $payment->currency));
        $this->log->log($payment->gateway, 'response', 'refund '.$result->status, $result->raw, $payment->invoice_id);

        if (! $result->succeeded()) {
            throw new BillingException(__('The gateway rejected the refund (:reason).', ['reason' => $result->declineReason ?? $result->status]));
        }

        return $result->txnId;
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
