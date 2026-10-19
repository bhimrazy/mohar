<?php

namespace App\Events\Support;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class TicketClosed implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * @param  string  $closedBy  client|staff|system
     */
    public function __construct(
        public int $ticketId,
        public ?int $clientId,
        public string $closedBy,
    ) {}
}
