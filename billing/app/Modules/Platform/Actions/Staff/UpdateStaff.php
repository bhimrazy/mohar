<?php

namespace App\Modules\Platform\Actions\Staff;

use App\Events\Core\StaffDisabled;
use App\Models\User;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Notifications\SecurityNotice;
use App\Modules\Platform\Services\PermissionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateStaff
{
    public function __construct(private PermissionGuard $guard, private ActivityLogger $activity) {}

    /**
     * @param  array{name: string, email: string, role_id: int, is_active: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, User $staff, array $data): User
    {
        $role = Role::query()->findOrFail($data['role_id']);
        $roleChanged = $staff->role_id !== $role->id;
        $deactivating = $staff->is_active && ! $data['is_active'];

        $this->guard->ensureCanGrant($actor, $staff->role->permissions ?? [], 'role_id');
        $this->guard->ensureCanGrant($actor, $role->permissions, 'role_id');

        if ($staff->is($actor) && ($roleChanged || $deactivating)) {
            throw ValidationException::withMessages([
                $deactivating ? 'is_active' : 'role_id' => __('You cannot change your own role or deactivate yourself.'),
            ]);
        }

        if ($this->guard->isFullAdmin($staff) && (! in_array('*', $role->permissions, true) || ! $data['is_active'])) {
            $this->guard->ensureAnotherFullAdmin(exceptUser: $staff, field: $deactivating ? 'is_active' : 'role_id');
        }

        $before = ['name' => $staff->name, 'email' => $staff->email, 'role' => $staff->role?->name, 'is_active' => $staff->is_active];
        $oldEmail = $staff->email;
        $newEmail = mb_strtolower(trim($data['email']));
        $emailChanged = $oldEmail !== $newEmail;

        $staff->forceFill([
            'name' => $data['name'],
            'email' => $newEmail,
            'role_id' => $role->id,
            'is_active' => $data['is_active'],
        ]);

        if ($deactivating) {
            $staff->forceFill(['remember_token' => Str::random(60)]);
        }

        if ($emailChanged && ! $staff->is($actor)) {
            // An admin-initiated change must be re-verified by the owner of the new address.
            $staff->forceFill(['email_verified_at' => null]);
        }

        $staff->save();

        if ($emailChanged) {
            Notification::route('mail', $oldEmail)->notify(new SecurityNotice(__('Your staff sign-in email was changed'), [
                __(':actor changed the sign-in email of your staff account from :old to :new.', ['actor' => $actor->name, 'old' => $oldEmail, 'new' => $newEmail]),
            ]));

            if (! $staff->is($actor)) {
                $staff->sendEmailVerificationNotification();
            }
        }

        if ($deactivating) {
            $this->revokeSessions($staff);
        }

        $after = ['name' => $staff->name, 'email' => $staff->email, 'role' => $role->name, 'is_active' => $staff->is_active];
        $diff = [];

        foreach ($before as $key => $old) {
            if ($old !== $after[$key]) {
                $diff[$key] = ['old' => $old, 'new' => $after[$key]];
            }
        }

        if ($diff !== []) {
            $event = match (true) {
                $deactivating => 'staff.disabled',
                $roleChanged => 'staff.role_changed',
                default => 'staff.updated',
            };
            $this->activity->log('Staff member updated', $staff, properties: ['diff' => $diff], event: $event);
        }

        if ($deactivating) {
            StaffDisabled::dispatch($staff->id);
        }

        return $staff;
    }

    /**
     * End every session of the user (database driver); the EnsureActiveUser middleware covers other drivers.
     */
    private function revokeSessions(User $staff): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table((string) config('session.table', 'sessions'))->where('user_id', $staff->id)->delete();
        }
    }
}
