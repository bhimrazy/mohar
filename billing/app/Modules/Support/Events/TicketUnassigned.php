<?php

namespace App\Modules\Support\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket lost its assignee (manual or after a department move).
 *
 * Module-local until promoted to App\Events\Support by the shared-kernel owner (spec §12).
 */
final class TicketUnassigned
{
    use Dispatchable;

    public function __construct(
        public int $ticketId,
        public int $previousStaffUserId,
    ) {}
}
