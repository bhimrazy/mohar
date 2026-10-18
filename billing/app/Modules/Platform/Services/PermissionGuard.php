<?php

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Modules\Core\Models\Role;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Privilege-escalation and lock-out rules for staff and roles:
 *  - staff can only grant (or assign roles with) permissions they hold themselves;
 *  - at least one active staff member holding "*" must always remain.
 */
class PermissionGuard
{
    public function __construct(private PermissionRegistry $registry) {}

    /**
     * Whether the actor's own role covers the permission (or wildcard) being granted.
     * Sensitive permissions can only be granted by staff who hold them explicitly (or "*").
     */
    public function canGrant(User $actor, string $permission): bool
    {
        if (! $actor->is_staff || ! $actor->is_active || $actor->role === null) {
            return false;
        }

        $sensitive = $this->registry->isSensitive($permission);

        foreach ($actor->role->permissions as $held) {
            if ($held === '*') {
                return true;
            }

            if ($permission === '*') {
                continue;
            }

            if ($held === $permission || (! $sensitive && str_ends_with($held, '.*') && str_starts_with($permission, substr($held, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string> the permissions the actor may not grant
     */
    public function ungrantable(User $actor, array $permissions): array
    {
        return array_values(array_filter($permissions, fn (string $permission): bool => ! $this->canGrant($actor, $permission)));
    }

    public function canManageRole(User $actor, Role $role): bool
    {
        return $this->ungrantable($actor, $role->permissions) === [];
    }

    /**
     * Whether the string is "*", a registered permission, or a "module.*" / "module.area.*" wildcard
     * that matches at least one registered permission.
     */
    public function isKnown(string $permission): bool
    {
        if ($permission === '*' || $this->registry->exists($permission)) {
            return true;
        }

        if (! preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)?\.\*$/', $permission)) {
            return false;
        }

        $prefix = substr($permission, 0, -1);

        foreach ($this->registry->all() as $permissions) {
            foreach (array_keys($permissions) as $registered) {
                if (str_starts_with($registered, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isFullAdmin(User $user): bool
    {
        return $user->is_staff && $user->is_active && $user->role !== null && in_array('*', $user->role->permissions, true);
    }

    /**
     * Throw a 422 unless an active full administrator other than the given user / outside the given role remains.
     *
     * @throws ValidationException
     */
    public function ensureAnotherFullAdmin(?User $exceptUser = null, ?Role $exceptRole = null, string $field = 'role_id'): void
    {
        $remaining = User::query()
            ->where('is_staff', true)
            ->where('is_active', true)
            ->when($exceptUser, fn ($query) => $query->whereKeyNot($exceptUser->getKey()))
            ->when($exceptRole, fn ($query) => $query->where('role_id', '!=', $exceptRole->getKey()))
            ->whereHas('role')
            ->with('role')
            ->get()
            ->contains(fn (User $user): bool => $user->role !== null && in_array('*', $user->role->permissions, true));

        if (! $remaining) {
            throw ValidationException::withMessages([
                $field => __('At least one active full administrator must remain.'),
            ]);
        }
    }

    /**
     * @param  list<string>  $permissions
     *
     * @throws ValidationException
     */
    public function ensureCanGrant(User $actor, array $permissions, string $field = 'permissions'): void
    {
        $denied = $this->ungrantable($actor, $permissions);

        if ($denied !== []) {
            throw ValidationException::withMessages([
                $field => __('You can only grant permissions you hold yourself: :permissions.', ['permissions' => implode(', ', $denied)]),
            ]);
        }
    }
}
