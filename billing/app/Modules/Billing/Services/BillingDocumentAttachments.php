<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\BillingDocuments;
use App\Data\Billing\DocumentFile;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Support\BillingSettings;

/**
 * A note's PDF for the note email (vat-register §10, AC 56), while "Attach PDFs to invoice and note emails" is on. It is
 * the cached original (RenderTaxNotePdf renders it after the note commits; a miss renders it here) and is not a print:
 * printing is a download (DocumentDownloads).
 */
class BillingDocumentAttachments implements BillingDocuments
{
    public function __construct(private TaxNotePdf $notePdf, private BillingSettings $settings) {}

    public function document(string $type, int $id, ?string $locale = null): ?DocumentFile
    {
        if ($type !== self::TAX_NOTE || ! $this->settings->bool('pdf.attach_to_emails')) {
            return null;
        }

        $note = TaxNote::query()->find($id);

        return $note === null ? null : new DocumentFile('objects', $this->notePdf->ensure($note, $locale), $this->notePdf->filename($note));
    }
}
