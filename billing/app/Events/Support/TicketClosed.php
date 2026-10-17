<?php

namespace App\Events\Support;

use Illuminate\Foundation\Events\Dispatchable;

final class TicketClosed
{
    use Dispatchable;

    public function __construct(
        public int $ticketId,
        public ?int $clientId,
    ) {}
}
