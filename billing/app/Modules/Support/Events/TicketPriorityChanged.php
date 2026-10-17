<?php

namespace App\Modules\Support\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff changed a ticket's priority.
 *
 * Module-local until promoted to App\Events\Support by the shared-kernel owner (spec §12).
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
