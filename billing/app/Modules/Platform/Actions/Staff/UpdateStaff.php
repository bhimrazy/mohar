<?php

namespace App\Modules\Platform\Actions\Staff;

use App\Events\Core\StaffDisabled;
use App\Models\User;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\LoginEmailChange;
use App\Modules\Core\Support\LoginEmailChanged;
use App\Modules\Core\Support\LoginSessions;
use App\Modules\Platform\Services\PermissionGuard;
use App\Modules\Platform\Support\AccountMail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateStaff
{
    public function __construct(private PermissionGuard $guard, private ActivityLogger $activity, private AccountMail $mail, private LoginEmailChange $emails) {}

    /**
     * @param  array{name: string, email: string, role_id: int, is_active: bool}  $data
     * @return LoginEmailChanged which mails about an email change were delivered (both true when it did not change);
     *                           the change is saved either way
     *
     * @throws ValidationException
     */
    public function handle(User $actor, User $staff, array $data): LoginEmailChanged
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

        if ($emailChanged) {
            // The new mailbox now receives password resets and has not been proven yet, whoever made the change.
            $staff->forceFill(['email_verified_at' => null]);
        }

        $staff->save();

        $result = new LoginEmailChanged(verificationSent: true, oldAddressTold: true);

        if ($emailChanged) {
            // Both addresses hear about it: the old one so a takeover is noticed, the new one for the record. Sent one
            // at a time, so the result says whether the old address was told (a failed copy to the new one is logged).
            $notice = ['actor' => $actor->name, 'old_email' => $oldEmail, 'new_email' => $newEmail];
            $lines = [__(':actor changed the sign-in email of your staff account from :old to :new.', ['actor' => $actor->name, 'old' => $oldEmail, 'new' => $newEmail])];
            $noticed = $this->mail->notice('staff.email_changed', [$oldEmail], $notice, 'staff:'.$staff->id, __('Your staff sign-in email was changed'), $lines);
            $this->mail->notice('staff.email_changed', [$newEmail], $notice, 'staff:'.$staff->id, __('Your staff sign-in email was changed'), $lines);

            $result = new LoginEmailChanged($this->emails->sendVerification($staff), $noticed);
        }

        if ($deactivating) {
            LoginSessions::revoke($staff);
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

        return $result;
    }
}
