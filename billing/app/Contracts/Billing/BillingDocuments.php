<?php

namespace App\Contracts\Billing;

use App\Data\Billing\DocumentFile;

/**
 * The PDF an email about a billing record carries. Notifications asks at send time with the message's related record,
 * so a resend attaches the document again. Billing binds it; without Billing nothing is attached.
 */
interface BillingDocuments
{
    /**
     * The document to attach, or null when the record type carries none, the record is gone, or attachments are off.
     * Today only `tax_note` (a credit or debit note) carries one.
     */
    public function emailAttachment(string $relatedType, int $relatedId): ?DocumentFile;
}
