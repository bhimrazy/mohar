<?php

namespace App\Modules\Support\Services;

use App\Contracts\Support\TicketStaffDirectory;
use App\Models\User;
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

    public function withDepartmentAccess(int $ticketId, array $userIds): array
    {
        $departmentId = Ticket::query()->whereKey($ticketId)->value('department_id');

        if ($departmentId === null || $userIds === []) {
            return [];
        }

        $access = app(TicketAccess::class);

        return array_values(User::query()->with('role')->whereIn('id', $userIds)->get()
            ->filter(fn (User $user): bool => $access->canAccessDepartment($user, (int) $departmentId))
            ->map(fn (User $user): int => $user->id)->all());
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
