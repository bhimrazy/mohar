<?php

namespace App\Modules\Platform\Actions\Staff;

use App\Events\Core\StaffCreated;
use App\Models\User;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Services\PermissionGuard;
use Illuminate\Support\Facades\DB;

/**
 * Create a staff account with a password chosen by an administrator; the staff member must change it on first login.
 */
class CreateStaff
{
    public function __construct(private PermissionGuard $guard, private ActivityLogger $activity) {}

    public function handle(User $actor, string $name, string $email, Role $role, string $password): User
    {
        $this->guard->ensureCanGrant($actor, $role->permissions, 'role_id');
        $email = mb_strtolower(trim($email));

        $user = DB::transaction(function () use ($name, $email, $role, $password): User {
            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill([
                'is_staff' => true,
                'role_id' => $role->id,
                'email_verified_at' => now(),
                'must_change_password' => true,
            ])->save();

            return $user;
        });

        $this->activity->log('Staff account created', $user, properties: ['email' => $email, 'role' => $role->name], event: 'staff.created');
        StaffCreated::dispatch($user->id);

        return $user;
    }
}
