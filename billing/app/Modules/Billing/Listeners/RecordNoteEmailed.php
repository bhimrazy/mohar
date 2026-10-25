<?php

namespace App\Modules\Billing\Listeners;

use App\Contracts\Billing\BillingDocuments;
use App\Events\Notifications\EmailSent;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Core\Support\ActivityLogger;

/**
 * `billing.tax_note.emailed` (vat-register §9): the note's audit trail says when it reached the transport, so staff can
 * answer "did the client get CN-12?".
 */
class RecordNoteEmailed
{
    public function __construct(private ActivityLogger $activity) {}

    public function handle(EmailSent $event): void
    {
        if ($event->relatedType !== BillingDocuments::TAX_NOTE || $event->relatedId === null) {
            return;
        }

        $note = TaxNote::query()->find($event->relatedId);

        if ($note !== null) {
            $this->activity->log(__(':kind :number emailed to the client', ['kind' => $note->kindLabel(), 'number' => $note->number]), $note, $note->client_id,
                ['number' => $note->number, 'email_message_id' => $event->emailMessageId, 'template' => $event->templateKey], 'billing.tax_note.emailed');
        }
    }
}
