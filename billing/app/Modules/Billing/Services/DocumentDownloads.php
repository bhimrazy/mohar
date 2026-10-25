<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF downloads of fiscal documents, admin and client alike (vat-register §9, F7, AC 55). Every download of a note, and
 * of a numbered invoice while Nepal VAT mode is on, is a print: the first is the original, each later one says
 * "Copy of original — print n". Other invoices are served as before, uncounted.
 */
class DocumentDownloads
{
    public function __construct(
        private InvoicePdf $invoicePdf,
        private TaxNotePdf $notePdf,
        private NepalVatMode $mode,
        private ActivityLogger $activity,
    ) {}

    public function invoice(Invoice $invoice): Response
    {
        $print = $invoice->numbered_at !== null && $this->mode->enabled() ? $this->count($invoice) : null;

        if ($print !== null && $print > 1) {
            return $this->attachment($this->invoicePdf->render($invoice, $print), $this->invoicePdf->filename($invoice));
        }

        return Storage::disk('objects')->download($this->invoicePdf->ensure($invoice), $this->invoicePdf->filename($invoice), ['Content-Type' => 'application/pdf']);
    }

    public function note(TaxNote $note): Response
    {
        $print = $this->count($note);

        if ($print > 1) {
            return $this->attachment($this->notePdf->render($note, $print), $this->notePdf->filename($note));
        }

        return Storage::disk('objects')->download($this->notePdf->ensure($note), $this->notePdf->filename($note), ['Content-Type' => 'application/pdf']);
    }

    /**
     * Takes the next print number under a row lock, so two downloads at once never share one, and logs it.
     */
    private function count(Invoice|TaxNote $document): int
    {
        return DB::transaction(function () use ($document): int {
            $locked = $document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            $locked->print_count = $locked->print_count + 1;
            $locked->save();
            $number = $document instanceof TaxNote ? $document->number : $document->displayNumber();

            $this->activity->log(__(':document :number printed (print :n)', ['document' => $document instanceof TaxNote ? $document->kindLabel() : __('Invoice'), 'number' => $number, 'n' => $locked->print_count]),
                $locked, $document->client_id, ['number' => $number, 'print_count' => $locked->print_count],
                $document instanceof TaxNote ? 'billing.tax_note.printed' : 'billing.invoice.printed');

            return $locked->print_count;
        });
    }

    private function attachment(string $pdf, string $filename): Response
    {
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
    }
}
