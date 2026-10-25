<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\BillingDocuments;
use App\Data\Billing\DocumentFile;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\QuoteStage;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\Settings;
use Illuminate\Support\Facades\Storage;

/**
 * A record's PDF for its email (vat-register §10, AC 56), while "Attach PDFs to invoice, quote and note emails" is on.
 * A note's is the cached original (RenderTaxNotePdf renders it after the note commits; a miss renders it here) and is not
 * a print: printing is a download (DocumentDownloads). An invoice's is its current version, so a resend after a payment
 * carries the tax invoice; a quote's is cached per edit.
 */
class BillingDocumentAttachments implements BillingDocuments
{
    public function __construct(private TaxNotePdf $notePdf, private InvoicePdf $invoicePdf, private BillingSettings $settings, private Settings $allSettings) {}

    public function document(string $type, int $id, ?string $locale = null): ?DocumentFile
    {
        if (! in_array($type, [self::TAX_NOTE, self::INVOICE, self::QUOTE], true) || ! $this->settings->bool('pdf.attach_to_emails')) {
            return null;
        }

        return match ($type) {
            self::TAX_NOTE => $this->note($id, $locale),
            self::INVOICE => $this->invoice($id, $locale),
            default => $this->quote($id, $locale),
        };
    }

    /**
     * Whether the email will carry the PDF, for its text (`tax_note.pdf_attached`, `invoice.pdf_attached`,
     * `quote.pdf_attached`): attachments are on, the record has a document, and the PDF in `$locale` is within
     * Notifications' size cap. A PDF not rendered yet counts as within it.
     */
    public function attaches(string $type, int $id, ?string $locale = null): bool
    {
        if (! $this->settings->bool('pdf.attach_to_emails')) {
            return false;
        }

        $path = match ($type) {
            self::TAX_NOTE => ($note = TaxNote::query()->find($id)) === null ? null : $this->notePdf->path($note, $locale),
            self::INVOICE => ($invoice = $this->issuedInvoice($id)) === null ? null : $this->invoicePdf->path($invoice, $locale),
            self::QUOTE => $this->sentQuote($id) === null ? null : '',
            default => null,
        };

        if ($path === null) {
            return false;
        }

        $cap = (int) $this->allSettings->get('notifications.attachment_max_bytes');
        $disk = Storage::disk('objects');

        return $cap <= 0 || $path === '' || ! $disk->exists($path) || $disk->size($path) <= $cap;
    }

    private function note(int $id, ?string $locale): ?DocumentFile
    {
        $note = TaxNote::query()->find($id);

        return $note === null ? null : new DocumentFile('objects', $this->notePdf->ensure($note, $locale), $this->notePdf->filename($note));
    }

    private function invoice(int $id, ?string $locale): ?DocumentFile
    {
        $invoice = $this->issuedInvoice($id);

        return $invoice === null ? null : new DocumentFile('objects', $this->invoicePdf->ensure($invoice, $locale), $this->invoicePdf->filename($invoice));
    }

    private function quote(int $id, ?string $locale): ?DocumentFile
    {
        $quote = $this->sentQuote($id);

        return $quote === null ? null : new DocumentFile('objects', $this->invoicePdf->ensureQuote($quote, $locale), $this->invoicePdf->quoteFilename($quote));
    }

    /** A draft has no number and is never sent. */
    private function issuedInvoice(int $id): ?Invoice
    {
        return Invoice::query()->whereKey($id)->where('status', '!=', InvoiceStatus::Draft)->first();
    }

    private function sentQuote(int $id): ?Quote
    {
        return Quote::query()->whereKey($id)->where('stage', '!=', QuoteStage::Draft)->first();
    }
}
