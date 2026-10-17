<?php

namespace App\Modules\Platform\Actions\Roles;

use App\Models\User;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Services\PermissionGuard;
use Illuminate\Validation\ValidationException;

class SaveRole
{
    public function __construct(private PermissionGuard $guard, private ActivityLogger $activity) {}

    /**
     * @param  array{name: string, description: ?string, permissions: list<string>}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, ?Role $role, array $data): Role
    {
        $permissions = array_values(array_unique($data['permissions']));
        sort($permissions);

        $this->guard->ensureCanGrant($actor, $permissions);

        if ($role !== null) {
            if (! $this->guard->canManageRole($actor, $role)) {
                throw ValidationException::withMessages(['permissions' => __('You cannot edit a role that has permissions you do not hold.')]);
            }

            if (in_array('*', $role->permissions, true) && ! in_array('*', $permissions, true) && $role->users()->where('is_active', true)->exists()) {
                $this->guard->ensureAnotherFullAdmin(exceptRole: $role, field: 'permissions');
            }
        }

        $before = $role === null ? [] : $role->permissions;
        $role ??= new Role;
        $role->fill(['name' => $data['name'], 'description' => $data['description'], 'permissions' => $permissions])->save();

        $this->activity->log($role->wasRecentlyCreated ? "Role {$role->name} created" : "Role {$role->name} updated", $role, properties: [
            'added' => array_values(array_diff($permissions, $before)),
            'removed' => array_values(array_diff($before, $permissions)),
        ], event: $role->wasRecentlyCreated ? 'role.created' : 'role.updated');

        return $role;
    }

    /**
     * @throws ValidationException
     */
    public function delete(User $actor, Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => __('System roles cannot be deleted.')]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages(['role' => __('Move the staff members in this role to another role first.')]);
        }

        if (! $this->guard->canManageRole($actor, $role)) {
            throw ValidationException::withMessages(['role' => __('You cannot delete a role that has permissions you do not hold.')]);
        }

        $role->delete();
        $this->activity->log("Role {$role->name} deleted", properties: ['permissions' => $role->permissions], event: 'role.deleted');
    }
}
