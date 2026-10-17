<?php

namespace App\Modules\Support\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket moved to another department.
 *
 * Module-local until promoted to App\Events\Support by the shared-kernel owner (spec §12).
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
