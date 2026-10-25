<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\BillingDocuments;
use App\Data\Billing\DocumentFile;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\Settings;
use Illuminate\Support\Facades\Storage;

/**
 * A note's PDF for the note email (vat-register §10, AC 56), while "Attach PDFs to invoice and note emails" is on. It is
 * the cached original (RenderTaxNotePdf renders it after the note commits; a miss renders it here) and is not a print:
 * printing is a download (DocumentDownloads).
 */
class BillingDocumentAttachments implements BillingDocuments
{
    public function __construct(private TaxNotePdf $notePdf, private BillingSettings $settings, private Settings $allSettings) {}

    public function document(string $type, int $id, ?string $locale = null): ?DocumentFile
    {
        if ($type !== self::TAX_NOTE || ! $this->settings->bool('pdf.attach_to_emails')) {
            return null;
        }

        $note = TaxNote::query()->find($id);

        return $note === null ? null : new DocumentFile('objects', $this->notePdf->ensure($note, $locale), $this->notePdf->filename($note));
    }

    /**
     * Whether the note email will carry the PDF, for its text (`tax_note.pdf_attached`): attachments are on and the PDF
     * in `$locale` is within Notifications' size cap. A PDF not rendered yet counts as within it; a note is a few
     * kilobytes, far below the cap.
     */
    public function attaches(TaxNote $note, ?string $locale = null): bool
    {
        if (! $this->settings->bool('pdf.attach_to_emails')) {
            return false;
        }

        $cap = (int) $this->allSettings->get('notifications.attachment_max_bytes');
        $path = $this->notePdf->path($note, $locale);
        $disk = Storage::disk('objects');

        return $cap <= 0 || ! $disk->exists($path) || $disk->size($path) <= $cap;
    }
}
