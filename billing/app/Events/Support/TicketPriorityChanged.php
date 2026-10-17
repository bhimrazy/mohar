<?php

namespace App\Events\Support;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff changed a ticket's priority.
 */
final class TicketPriorityChanged
{
    use Dispatchable;

    public function __construct(
        public int $ticketId,
        public string $from,
        public string $to,
    ) {}
}
