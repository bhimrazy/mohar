<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The invoices an automatic path could not write a note for (a cancel or a late fee, VAT PR 3b): recorded on the staff
 * review list (the dashboard's "Needs attention") and in the activity log, so a run that skips an invoice never hides it.
 * A later successful cancel marks its entry seen.
 */
class NoteRefusals
{
    public function __construct(private ActivityLogger $activity) {}

    public function record(Invoice $invoice, string $reason, string $message): void
    {
        $now = now();
        DB::table('invoice_fiscal_reviews')->insertOrIgnore(['invoice_id' => $invoice->id, 'reason' => $reason, 'subject' => 'invoice', 'detail' => mb_substr($message, 0, 500), 'created_at' => $now, 'updated_at' => $now]);
        $this->activity->log($reason === InvoiceFiscalReview::CANCEL_REFUSED ? 'Invoice not cancelled: its credit note was refused' : 'Late fee not added: its debit note was refused', $invoice, $invoice->client_id, ['reason' => $message], 'billing.note_refused');
    }

    /** The invoice was cancelled after all: its refusal needs no one. */
    public function clear(int $invoiceId): void
    {
        DB::table('invoice_fiscal_reviews')->where('invoice_id', $invoiceId)->where('reason', InvoiceFiscalReview::CANCEL_REFUSED)->whereNull('seen_at')->update(['seen_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The invoices that still need someone: an unseen refusal whose invoice is still payable. An entry resolves itself when the
     * invoice is cancelled or paid after all, so the count and the list behind it can fall to zero.
     *
     * @return Builder<InvoiceFiscalReview>
     */
    private function openEntries(): Builder
    {
        return InvoiceFiscalReview::query()->whereIn('reason', [InvoiceFiscalReview::CANCEL_REFUSED, InvoiceFiscalReview::LATE_FEE_REFUSED])->unseen()
            ->whereIn('invoice_id', Invoice::query()->whereIn('status', InvoiceStatus::payable())->select('id'));
    }

    public function open(): int
    {
        return $this->openEntries()->distinct()->count('invoice_id');
    }

    /** @return list<int> */
    public function invoiceIds(): array
    {
        return array_values($this->openEntries()->distinct()->pluck('invoice_id')->map(fn (mixed $id): int => (int) $id)->all());
    }
}
