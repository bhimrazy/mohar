<?php

namespace App\Modules\Billing\Actions;

use App\Events\Billing\InvoiceModified;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Support\AfterCommit;
use App\Modules\Core\Models\Client;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A dispute the merchant won (#191): the provider returned the disputed money, so what the dispute's chargeback rows
 * undid is put back, once per dispute id.
 *
 * - The invoice part goes back on the invoice, up to what it still owes, and the payment can be refunded again by
 *   that much; an invoice it brings to zero is paid again. An add-funds invoice gets its deposit credit back.
 * - The credit the dispute took back (its `(gateway, dispute id)` ledger line) is restored, exactly that line.
 * - A rest nobody could cover is cleared from staff review, since that money came back too.
 * - What the invoice can no longer take (it was paid again, or cancelled, since the dispute) is never turned into
 *   credit: it goes to staff review on the reversal row ("Disputes to review").
 *
 * The reversal row (`chargeback_reversal`, the dispute id) books everything the chargeback booked out as money in; the
 * unique `(gateway, gateway_txn_id, type)` index stops a second one. Locks run payment, invoice, client.
 */
class RecordDisputeWon
{
    public function __construct(
        private CreditLedgerService $credit,
        private InvoiceSettlement $settlement,
    ) {}

    public function existing(string $gateway, string $disputeId): ?Transaction
    {
        return Transaction::query()->where('gateway', $gateway)->where('gateway_txn_id', $disputeId)->where('type', TransactionType::ChargebackReversal)->first();
    }

    /**
     * @param  Transaction  $chargeback  the dispute's chargeback row (the one that carries the dispute id)
     * @param  array<string, mixed>  $raw  redacted provider payload
     * @return Transaction the reversal row; not recently created when another delivery booked it first
     */
    public function handle(Transaction $chargeback, array $raw = []): Transaction
    {
        return DB::transaction(function () use ($chargeback, $raw): Transaction {
            $disputeId = (string) $chargeback->gateway_txn_id;
            $rows = $this->rows($chargeback);
            $payments = Transaction::query()->whereIn('id', $rows->pluck('parent_transaction_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // Re-read under the payment lock: a concurrent delivery may have booked the win meanwhile.
            if (($won = $this->existing($chargeback->gateway, $disputeId)) !== null) {
                return $won;
            }

            $invoices = Invoice::query()->whereIn('id', $payments->pluck('invoice_id')->filter())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            Client::query()->whereKey($chargeback->client_id)->lockForUpdate()->firstOrFail();

            $first = $rows->first();
            $takenLine = CreditLedgerEntry::query()->where('gateway', $chargeback->gateway)->where('external_ref', $disputeId)->where('amount', '<', 0)->first();
            $taken = $takenLine === null ? 0 : -$takenLine->amount;

            $won = Transaction::query()->create([
                'client_id' => $first->client_id,
                'invoice_id' => $first->invoice_id,
                'payment_batch_id' => $first->payment_batch_id,
                'gateway' => $first->gateway,
                'gateway_txn_id' => $disputeId,
                'type' => TransactionType::ChargebackReversal,
                'status' => TransactionStatus::Succeeded,
                'amount_in' => (int) $rows->sum('amount_out'),
                'currency' => $first->currency,
                'parent_transaction_id' => $first->id,
                'description' => __('Dispute won: payment reinstated by the gateway'),
                'raw' => $raw,
                'occurred_at' => now(),
            ]);

            $review = 0;

            foreach ($rows as $row) {
                // The invoice part of the row: the first also booked the credit taken back and any rest nobody covered.
                $portion = $row->amount_out - ($row->is($first) ? $taken + (int) $row->review_amount : 0);
                $payment = $payments->get($row->parent_transaction_id);
                $invoice = $payment?->invoice_id !== null ? $invoices->get($payment->invoice_id) : null;

                if ($portion <= 0 || $payment === null) {
                    continue;
                }

                $applied = $invoice !== null && $invoice->status->isPayable() ? min($portion, max(0, $invoice->balance)) : 0;
                $review += $portion - $applied;

                if ($applied > 0) {
                    $payment->refundable_remaining += $applied;
                    $payment->save();
                    $this->reinstate($invoice, $applied, $won);
                }
            }

            if ($taken > 0) {
                $this->credit->post(
                    $chargeback->client_id,
                    Money::of($taken, $takenLine->currency),
                    CreditReason::Reversal,
                    $takenLine->invoice_id,
                    $takenLine->transaction_id,
                    mb_substr(__('Credit restored (dispute :dispute won)', ['dispute' => $disputeId]), 0, 255),
                    gateway: $chargeback->gateway,
                    externalRef: mb_substr($disputeId.':won', 0, 191),
                );
            }

            if ($first->review_amount !== null && $first->reviewed_at === null) {
                $first->update(['reviewed_at' => now()]);
            }

            if ($review > 0) {
                $won->update(['review_amount' => $review]);
            }

            return $won;
        });
    }

    /**
     * The dispute's chargeback rows: the one with the dispute id, plus, for a combined charge (payment batch), the
     * allocations booked with it, which carry no id. A provider charge has at most one dispute.
     *
     * @return Collection<int, Transaction>
     */
    private function rows(Transaction $chargeback): Collection
    {
        $rows = collect([$chargeback]);

        if ($chargeback->payment_batch_id === null) {
            return $rows;
        }

        return $rows->merge(Transaction::query()->where('type', TransactionType::Chargeback)->where('gateway', $chargeback->gateway)
            ->where('payment_batch_id', $chargeback->payment_batch_id)->whereNull('gateway_txn_id')->orderBy('id')->get());
    }

    private function reinstate(Invoice $invoice, int $amount, Transaction $won): void
    {
        $invoice->amount_paid += $amount;
        $invoice->recalculateBalance();

        if ($invoice->kind === InvoiceKind::AddFunds) {
            // settle() would post the whole deposit again; only what the chargeback removed comes back.
            if ($invoice->balance <= 0) {
                $invoice->status = InvoiceStatus::Paid;
            }

            $invoice->save();
            $this->credit->post($invoice->client_id, Money::of($amount, $invoice->currency), CreditReason::Reversal, $invoice->id, $won->id, __('Deposit restored (dispute won)'));
            AfterCommit::dispatch(new InvoiceModified($invoice->id, $invoice->client_id));

            return;
        }

        $invoice->save();

        if (! $this->settlement->settle($invoice)) {
            AfterCommit::dispatch(new InvoiceModified($invoice->id, $invoice->client_id));
        }
    }
}
