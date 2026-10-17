<?php

namespace App\Modules\Support\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A closed ticket was reopened (client/guest/email reply or staff status change).
 *
 * Module-local until promoted to App\Events\Support by the shared-kernel owner (spec §12).
 */
final class TicketReopened
{
    use Dispatchable;

    public function __construct(
        public int $ticketId,
        public ?int $clientId,
        public string $by,
    ) {}
}
