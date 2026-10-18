<?php

namespace App\Events\Support;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff changed a ticket's status explicitly.
 */
final class TicketStatusChanged
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $ticketId,
        public string $from,
        public string $to,
    ) {}
}
