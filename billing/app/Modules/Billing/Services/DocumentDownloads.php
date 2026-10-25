<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Core\Support\ActivityLogger;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF downloads of fiscal documents, admin and client alike (vat-register §9, F7, AC 55). While Nepal VAT mode is on,
 * each GET of a note or of a numbered invoice is a print: the first is the original, each later one says
 * "Copy of original — print n". A HEAD request, and any download with the mode off, is served uncounted.
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
        $filename = $this->invoicePdf->filename($invoice);
        $original = fn (): Response => Storage::disk('objects')->download($this->invoicePdf->ensure($invoice), $filename, ['Content-Type' => 'application/pdf']);

        return $invoice->numbered_at !== null && $this->counts()
            ? $this->print($invoice, $original, fn (int $print): Response => $this->attachment($this->invoicePdf->render($invoice, $print), $filename))
            : $original();
    }

    public function note(TaxNote $note): Response
    {
        $filename = $this->notePdf->filename($note);
        $original = fn (): Response => Storage::disk('objects')->download($this->notePdf->ensure($note), $filename, ['Content-Type' => 'application/pdf']);

        return $this->counts()
            ? $this->print($note, $original, fn (int $print): Response => $this->attachment($this->notePdf->render($note, $print), $filename))
            : $original();
    }

    private function counts(): bool
    {
        return request()->isMethod('GET') && $this->mode->enabled();
    }

    /**
     * Takes the next print number under the document's row lock and renders that print before committing, so two
     * downloads at once never share a number and a render that fails takes none. The lock is held while dompdf runs
     * (about a second at most for one document).
     *
     * @param  Closure(): Response  $original
     * @param  Closure(int): Response  $copy
     */
    private function print(Invoice|TaxNote $document, Closure $original, Closure $copy): Response
    {
        return DB::transaction(function () use ($document, $original, $copy): Response {
            $locked = $document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            $print = $locked->print_count + 1;
            $response = $print === 1 ? $original() : $copy($print);
            $locked->print_count = $print;
            $locked->save();
            $number = $locked instanceof TaxNote ? $locked->number : $locked->displayNumber();

            $this->activity->log(__(':document :number printed (print :n)', ['document' => $locked instanceof TaxNote ? $locked->kindLabel() : __('Invoice'), 'number' => $number, 'n' => $print]),
                $locked, $locked->client_id, ['number' => $number, 'print_count' => $print],
                $locked instanceof TaxNote ? 'billing.tax_note.printed' : 'billing.invoice.printed');

            return $response;
        });
    }

    private function attachment(string $pdf, string $filename): Response
    {
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
    }
}
