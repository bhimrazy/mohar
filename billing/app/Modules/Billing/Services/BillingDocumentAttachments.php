<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\BillingDocuments;
use App\Data\Billing\DocumentFile;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Support\BillingSettings;

/**
 * The note's PDF on the note email (vat-register §10, AC 56), while "Attach PDFs to invoice emails" is on. The attachment
 * is the cached original and is not counted as a print: printing is a download (DocumentDownloads).
 */
class BillingDocumentAttachments implements BillingDocuments
{
    public function __construct(private TaxNotePdf $notePdf, private BillingSettings $settings) {}

    public function emailAttachment(string $relatedType, int $relatedId): ?DocumentFile
    {
        if ($relatedType !== 'tax_note' || ! $this->settings->bool('pdf.attach_to_emails')) {
            return null;
        }

        $note = TaxNote::query()->find($relatedId);

        return $note === null ? null : new DocumentFile('objects', $this->notePdf->ensure($note), $this->notePdf->filename($note));
    }
}
