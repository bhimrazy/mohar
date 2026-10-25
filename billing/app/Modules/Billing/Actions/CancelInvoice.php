<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Core\CompanyClock;
use App\Events\Billing\InvoiceCancelled;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Billing\Services\NoteRefusals;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Billing\Support\AfterCommit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a draft or unpaid invoice: a cancellation credit note zeroes the balance and the double-invoicing
 * guards are released (the invoice keeps its rows for the audit trail).
 */
class CancelInvoice
{
    public function __construct(
        private InvoiceSettlement $settlement,
        private CreditLedgerService $credit,
        private TaxNoteIssuer $notes,
        private NepalVatMode $mode,
        private CompanyClock $clock,
        private NoteRefusals $refusals,
    ) {}

    /**
     * With Nepal VAT mode in effect, cancelling a numbered tax invoice issues a credit note for its open balance instead of
     * a cancellation adjustment (vat-register §5.4); the note's reason is `$note`, which staff must give.
     *
     * @param  bool  $strict  throw instead of silently ignoring invoices that can't be cancelled
     * @param  bool  $notifyClient  false keeps the cancelling credit note from being emailed (Invoicer::cancel)
     */
    public function handle(Invoice $invoice, ?int $userId = null, ?string $note = null, bool $strict = true, bool $notifyClient = true): Invoice
    {
        return DB::transaction(function () use ($invoice, $userId, $note, $strict, $notifyClient): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Unpaid, InvoiceStatus::PaymentPending], true)) {
                if ($strict && $invoice->status !== InvoiceStatus::Cancelled) {
                    throw new BillingException(__('Only draft or unpaid invoices can be cancelled. Refund paid invoices instead.'));
                }

                return $invoice;
            }

            // One instant for everything this transaction writes, taken after the lock; the mode is read under a shared lock.
            $at = $this->clock->now()->utc();
            $fiscal = $invoice->numbered_at !== null && $invoice->kind !== InvoiceKind::AddFunds && $this->mode->inEffectLocked($at);

            if ($fiscal && $userId !== null && trim((string) $note) === '') {
                throw new BillingException(__('Say why the invoice is cancelled: the reason is printed on the credit note.'));
            }

            // Account credit applied to the invoice goes back to the client's balance first; payments stay on the
            // invoice (staff refund them per transaction).
            if ($invoice->credit_applied > 0) {
                $returned = $invoice->credit_applied;
                $invoice->credit_applied = 0;
                $this->settlement->adjust($invoice, AdjustmentKind::Debit, AdjustmentReason::CreditRemoved, $returned, __('Account credit returned on cancellation'), userId: $userId, at: $at);
                $this->credit->post($invoice->client_id, Money::of($returned, $invoice->currency), CreditReason::CreditRemoved, $invoice->id, description: __('Returned from cancelled invoice :number', ['number' => $invoice->displayNumber()]), staffId: $userId);
            }

            if ($invoice->status !== InvoiceStatus::Draft && $invoice->balance > 0) {
                if ($fiscal) {
                    $this->notes->issueNow(NoteRequest::cancellation($invoice->id, $invoice->balance, $note ?? __('Invoice cancelled'), $userId === null, $userId)->notifying($notifyClient), $at, settle: false);
                    $invoice->refresh();
                } else {
                    $this->settlement->adjust($invoice, AdjustmentKind::Credit, AdjustmentReason::Cancellation, $invoice->balance, $note ?? __('Invoice cancelled'), userId: $userId, at: $at);
                }
            }

            $invoice->status = InvoiceStatus::Cancelled;
            $invoice->cancelled_at = $at;

            if ($note !== null) {
                $invoice->admin_notes = trim(($invoice->admin_notes ?? '')."\n".$note);
            }

            $invoice->save();
            $this->settlement->releaseGuards($invoice);
            $this->refusals->clear($invoice->id);

            AfterCommit::dispatch(new InvoiceCancelled($invoice->id, $invoice->client_id));

            return $invoice;
        });
    }
}
