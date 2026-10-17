<?php

namespace App\Events\Support;

use Illuminate\Foundation\Events\Dispatchable;

final class TicketOpened
{
    use Dispatchable;

    public function __construct(
        public int $ticketId,
        public ?int $clientId,
        public int $departmentId,
        public bool $byStaff,
    ) {}
}
