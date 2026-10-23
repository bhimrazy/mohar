<?php

namespace App\Modules\Core\Support;

use App\Contracts\Support\TicketStaffDirectory;

/**
 * Default when Support is not installed: no tickets, so no members and no assignee.
 */
class NullTicketStaffDirectory implements TicketStaffDirectory
{
    public function newTicketMembers(int $ticketId): array
    {
        return [];
    }

    public function replyMembers(int $ticketId): array
    {
        return [];
    }

    public function assigneeId(int $ticketId): ?int
    {
        return null;
    }
}
