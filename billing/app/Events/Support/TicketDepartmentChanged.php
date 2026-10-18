<?php

namespace App\Events\Support;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket moved to another department.
 */
final class TicketDepartmentChanged
{
    use Dispatchable;

    public function __construct(
        public int $ticketId,
        public int $fromDepartmentId,
        public int $toDepartmentId,
    ) {}
}
