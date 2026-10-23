<?php

namespace App\Modules\Support\Services;

use App\Contracts\Support\TicketStaffDirectory;
use App\Modules\Support\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * TicketStaffDirectory: the department's members (with their notify flags) and the assignee, read at call time.
 */
class SupportTicketStaff implements TicketStaffDirectory
{
    public function newTicketMembers(int $ticketId): array
    {
        return $this->members($ticketId, 'notify_new');
    }

    public function replyMembers(int $ticketId): array
    {
        return $this->members($ticketId, 'notify_replies');
    }

    public function assigneeId(int $ticketId): ?int
    {
        $id = Ticket::query()->whereKey($ticketId)->value('assigned_to');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  'notify_new'|'notify_replies'  $flag
     * @return list<int>
     */
    private function members(int $ticketId, string $flag): array
    {
        return array_values(array_map('intval', DB::table('support_department_user')
            ->whereIn('department_id', Ticket::query()->whereKey($ticketId)->select('department_id'))
            ->where($flag, true)->orderBy('user_id')->limit(200)->pluck('user_id')->all()));
    }
}
