<?php

namespace App\Contracts\Billing;

use App\Data\Billing\DocumentFile;

/**
 * A billing record's PDF, for an email to carry. Billing answers "what is this record's document"; Notifications decides
 * per template whether a message carries it and asks at send time with the message's related record and locale, so a
 * resend attaches it again. Billing binds it; without Billing nothing is attached.
 *
 * `$type` values are the merge-field entity keys, the same strings `WebhookEvents` registrations use.
 */
interface BillingDocuments
{
    /** A credit or debit note. */
    public const TAX_NOTE = 'tax_note';

    /**
     * The document in `$locale` (the app locale when null), or null when the type has no document, the record is gone,
     * or Billing's "Attach PDFs to emails" setting is off.
     */
    public function document(string $type, int $id, ?string $locale = null): ?DocumentFile;
}
