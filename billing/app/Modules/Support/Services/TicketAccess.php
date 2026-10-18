<?php

namespace App\Modules\Support\Services;

use App\Models\User;
use App\Modules\Support\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Staff department scoping (spec §2 department_staff): staff see and act only on tickets of
 * departments they are assigned to, unless they hold support.tickets.access_all.
 */
class TicketAccess
{
    public const ACCESS_ALL = 'support.tickets.access_all';

    /** @var array<int, list<int>> */
    private array $departmentCache = [];

    public function hasFullAccess(User $user): bool
    {
        return $user->hasPermission(self::ACCESS_ALL);
    }

    /**
     * @return list<int>
     */
    public function departmentIds(User $user): array
    {
        return $this->departmentCache[$user->id] ??= array_values(array_map('intval', DB::table('support_department_user')
            ->where('user_id', $user->id)
            ->pluck('department_id')
            ->all()));
    }

    public function canAccessDepartment(User $user, int $departmentId): bool
    {
        return $user->is_staff && ($this->hasFullAccess($user) || in_array($departmentId, $this->departmentIds($user), true));
    }

    public function canAccess(User $user, Ticket $ticket): bool
    {
        return $this->canAccessDepartment($user, $ticket->department_id);
    }

    /**
     * Restrict a ticket query to what the staff member may see.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if ($this->hasFullAccess($user)) {
            return $query;
        }

        return $query->whereIn('department_id', $this->departmentIds($user));
    }

    /**
     * Whether a staff member may be assigned tickets of the department (spec §4.4).
     */
    public function isEligibleAssignee(User $candidate, int $departmentId): bool
    {
        return $this->canAccessDepartment($candidate, $departmentId);
    }

    public function forget(): void
    {
        $this->departmentCache = [];
    }
}
