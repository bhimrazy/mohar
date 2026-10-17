<?php

namespace App\Events\Support;

use Illuminate\Foundation\Events\Dispatchable;

final class TicketClosed
{
    use Dispatchable;

    /**
     * @param  string  $closedBy  client|staff|system
     */
    public function __construct(
        public int $ticketId,
        public ?int $clientId,
        public string $closedBy,
    ) {}
}
