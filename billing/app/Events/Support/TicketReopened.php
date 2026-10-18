<?php

namespace App\Events\Support;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A closed ticket was reopened (client/guest/email reply or staff status change).
 */
final class TicketReopened
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $ticketId,
        public ?int $clientId,
        public string $by,
    ) {}
}
