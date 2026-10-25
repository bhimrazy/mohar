<?php

namespace App\Events\Billing;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff asked to send a credit or debit note to the client again. Each dispatch is a new occurrence, so each is a new
 * email; it is not a webhook event (the note was issued once). $kind is `credit` or `debit`.
 */
final class TaxNoteResent implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $taxNoteId,
        public string $kind,
        public int $invoiceId,
        public int $clientId,
    ) {}
}
