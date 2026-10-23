<?php

namespace App\Events\Support;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A guest ticket's access key was replaced because tickets of other requesters were merged into it. The old link no
 * longer opens the ticket; the requester gets the new one by email.
 */
final class TicketAccessLinkReplaced implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $ticketId,
        public ?int $clientId,
    ) {}
}
