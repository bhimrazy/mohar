<?php

namespace App\Events\Support;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class TicketOpened implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $ticketId,
        public ?int $clientId,
        public int $departmentId,
        public bool $byStaff,
    ) {}
}
