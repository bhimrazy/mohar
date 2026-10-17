<?php

namespace App\Modules\Billing\Actions;

use App\Events\Billing\PaymentReversed;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Support\AfterCommit;
use App\Modules\Billing\Support\BillingSettings;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Payment reversal reported by the gateway (§4.8): chargeback transaction, invoice → collections,
 * add-funds credit removed (may go negative), PaymentReversed for Services/Affiliates.
 */
class RecordChargeback
{
    public function __construct(
        private CreditLedgerService $credit,
        private BillingSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public function handle(Transaction $payment, int $amount, string $gatewayTxnId, array $raw = []): Transaction
    {
        return DB::transaction(function () use ($payment, $amount, $gatewayTxnId, $raw): Transaction {
            $payment = Transaction::query()->lockForUpdate()->findOrFail($payment->id);

            $existing = Transaction::query()->where('gateway', $payment->gateway)->where('gateway_txn_id', $gatewayTxnId)->where('type', TransactionType::Chargeback)->first();

            if ($existing !== null) {
                return $existing;
            }

            $amount = min($amount, $payment->refundable_remaining);

            $chargeback = Transaction::query()->create([
                'client_id' => $payment->client_id,
                'invoice_id' => $payment->invoice_id,
                'gateway' => $payment->gateway,
                'gateway_txn_id' => $gatewayTxnId,
                'type' => TransactionType::Chargeback,
                'status' => TransactionStatus::Succeeded,
                'amount_out' => $amount,
                'currency' => $payment->currency,
                'parent_transaction_id' => $payment->id,
                'description' => __('Payment reversed by the gateway (chargeback)'),
                'raw' => $raw,
                'occurred_at' => now(),
            ]);

            $payment->refundable_remaining -= $amount;
            $payment->save();

            $invoice = $payment->invoice_id !== null ? Invoice::query()->lockForUpdate()->find($payment->invoice_id) : null;

            if ($invoice === null) {
                return $chargeback;
            }

            $invoice->amount_paid -= $amount;
            $invoice->recalculateBalance();

            if ($this->settings->bool('reversal.set_collections') && in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Unpaid, InvoiceStatus::PaymentPending], true)) {
                $invoice->status = InvoiceStatus::Collections;
            }

            $invoice->save();

            if ($invoice->kind === InvoiceKind::AddFunds) {
                $this->credit->post($invoice->client_id, Money::of(-$amount, $invoice->currency), CreditReason::Reversal, $invoice->id, $chargeback->id, __('Deposit reversed (chargeback)'), allowNegative: true);
            }

            AfterCommit::dispatch(new PaymentReversed($invoice->id, $invoice->client_id, $chargeback->id, $amount, $invoice->currency, 'chargeback'));

            return $chargeback;
        });
    }
}
