<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Orders\PaymentGuard;
use App\Events\Billing\InvoicePartiallyPaid;
use App\Events\Billing\PaymentReceived;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\GatewayLogger;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Support\AfterCommit;
use App\Modules\Core\Models\Client;
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
        private PaymentGuard $guard,
        private GatewayLogger $log,
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

        $refusal = $invoice->status->isPayable() ? $this->guard->refusalReason($invoice->id) : null;

        if ($refusal !== null && ! $payment->fromGateway) {
            throw new BillingException($refusal);
        }

        // A payment that will post account credit (closed or refused invoice, an overpayment, a deposit paid in full)
        // locks the client before its row is inserted (#224). The insert's foreign key takes a key-share lock on the
        // client; two such payments of one client on different invoices would each hold it and then wait on each other
        // for the credit post's FOR UPDATE, which PostgreSQL aborts as a deadlock (40P01). Only those lock it: a
        // payment of several invoices locks all of them first (ADR 0021), and must not hold the client in between.
        $credits = ! $invoice->status->isPayable() || $refusal !== null || $payment->amount > $invoice->balance
            || ($invoice->kind === InvoiceKind::AddFunds && $payment->amount >= $invoice->balance);

        if ($credits) {
            Client::query()->whereKey($invoice->client_id)->lockForUpdate()->firstOrFail();
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

        if (! $invoice->status->isPayable() || $refusal !== null) {
            // Closed invoice, or payment refused for the order (fraud review): keep the money as account credit
            // and let staff decide.
            $description = $refusal !== null
                ? __('Payment held for invoice :number: :reason', ['number' => $invoice->displayNumber(), 'reason' => $refusal])
                : __('Payment received for closed invoice :number', ['number' => $invoice->displayNumber()]);
            $this->credit->post($invoice->client_id, Money::of($payment->amount, $invoice->currency), CreditReason::Overpayment, $invoice->id, $transaction->id, mb_substr($description, 0, 255));
            $transaction->update(['refundable_remaining' => 0]);

            if ($refusal !== null) {
                $this->log->log($payment->gateway, 'callback', 'payment held for review', ['transaction' => $transaction->id, 'reason' => $refusal], $invoice->id);
            }

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
