<?php

namespace App\Events\Billing;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A credit note was issued against an invoice (ADR 0024 §6): it has its own number and is a legal document once issued.
 * Dispatched with `event()` after the note commits, never through `AfterCommit::dispatch` (that would defer it twice).
 *
 * $amount is positive (the note's gross total), in minor units of $currency. $automatic is true when automation, a gateway callback or another
 * module issued the note, false when staff did.
 */
final class CreditNoteIssued implements DomainEvent, ShouldDispatchAfterCommit
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $taxNoteId,
        public string $number,
        public int $invoiceId,
        public int $clientId,
        public int $amount,
        public string $currency,
        public bool $automatic,
    ) {}
}
