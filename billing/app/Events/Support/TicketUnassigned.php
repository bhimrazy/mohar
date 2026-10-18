<?php

namespace App\Events\Support;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket lost its assignee (manual or after a department move).
 */
final class TicketUnassigned
{
    use Dispatchable;

    public function __construct(
        public int $ticketId,
        public int $previousStaffUserId,
    ) {}
}
