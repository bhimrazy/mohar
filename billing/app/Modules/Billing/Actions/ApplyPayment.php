<?php

namespace App\Modules\Billing\Actions;

use App\Events\Billing\InvoicePartiallyPaid;
use App\Events\Billing\PaymentReceived;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Support\AfterCommit;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Record a payment against an invoice (§4.1). Idempotent per (gateway, gateway_txn_id): a repeated callback
 * returns the existing transaction. Overpayments become account credit; InvoicePaid fires only on the transition.
 */
class ApplyPayment
{
    public function __construct(
        private InvoiceSettlement $settlement,
        private CreditLedgerService $credit,
    ) {}

    public function handle(Invoice $invoice, PaymentData $payment): Transaction
    {
        if ($payment->amount <= 0) {
            throw new BillingException(__('Payment amounts must be greater than zero.'));
        }

        try {
            return DB::transaction(fn (): Transaction => $this->record($invoice->id, $payment));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($payment) ?? throw $exception;
        }
    }

    public function existing(PaymentData $payment): ?Transaction
    {
        if ($payment->gatewayTxnId === null) {
            return null;
        }

        return Transaction::query()
            ->where('gateway', $payment->gateway)
            ->where('gateway_txn_id', $payment->gatewayTxnId)
            ->where('type', $payment->type)
            ->first();
    }

    private function record(int $invoiceId, PaymentData $payment): Transaction
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

        if ($duplicate = $this->existing($payment)) {
            return $duplicate;
        }

        $transaction = Transaction::query()->create([
            'client_id' => $invoice->client_id,
            'invoice_id' => $invoice->id,
            'payment_batch_id' => $payment->paymentBatchId,
            'pay_method_id' => $payment->payMethodId,
            'gateway' => $payment->gateway,
            'gateway_txn_id' => $payment->gatewayTxnId,
            'type' => $payment->type,
            'status' => TransactionStatus::Succeeded,
            'amount_in' => $payment->amount,
            'fee' => $payment->fee,
            'currency' => $invoice->currency,
            'gateway_amount' => $payment->gatewayAmount,
            'gateway_currency' => $payment->gatewayCurrency,
            'exchange_rate' => $payment->exchangeRate,
            'refundable_remaining' => $payment->amount,
            'description' => $payment->description,
            'raw' => $payment->raw,
            'created_by' => $payment->createdBy,
            'occurred_at' => $payment->occurredAt ?? now(),
        ]);

        AfterCommit::dispatch(new PaymentReceived($transaction->id, $invoice->id, $invoice->client_id, $payment->amount, $invoice->currency, $payment->gateway));

        if (! $invoice->status->isPayable()) {
            // Closed invoice (paid/cancelled/refunded): keep the money as account credit and let staff decide.
            $this->credit->post($invoice->client_id, Money::of($payment->amount, $invoice->currency), CreditReason::Overpayment, $invoice->id, $transaction->id, __('Payment received for closed invoice :number', ['number' => $invoice->displayNumber()]));
            $transaction->update(['refundable_remaining' => 0]);

            return $transaction;
        }

        $invoice->amount_paid += $payment->amount;
        $invoice->payment_gateway ??= $payment->gateway;

        if ($invoice->status === InvoiceStatus::PaymentPending) {
            $invoice->status = InvoiceStatus::Unpaid;
        }

        $invoice->recalculateBalance();
        $invoice->save();

        $credited = $this->settlement->absorbOverpayment($invoice, $transaction->id);

        if ($credited > 0) {
            $transaction->update(['refundable_remaining' => $payment->amount - $credited]);
        }

        if (! $this->settlement->settle($invoice)) {
            AfterCommit::dispatch(new InvoicePartiallyPaid($invoice->id, $invoice->client_id, $invoice->amount_paid, $invoice->balance, $invoice->currency));
        }

        return $transaction;
    }
}
