<?php

namespace App\Modules\Platform\Actions\Staff;

use App\Events\Core\TwoFactorReset;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Notifications\SecurityNotice;
use App\Modules\Platform\Services\PermissionGuard;
use Illuminate\Validation\ValidationException;

class ResetStaffTwoFactor
{
    public function __construct(private PermissionGuard $guard, private ActivityLogger $activity) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $staff): void
    {
        if ($staff->is($actor)) {
            throw ValidationException::withMessages(['two_factor' => __('Use your own security settings to change your two-factor authentication.')]);
        }

        $this->guard->ensureCanGrant($actor, $staff->role->permissions ?? [], 'two_factor');

        $staff->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->activity->log('Two-factor authentication reset for a staff member', $staff, properties: ['email' => $staff->email], event: 'staff.two_factor_reset');
        $staff->notify(new SecurityNotice(__('Your two-factor authentication was reset'), [
            __(':actor reset two-factor authentication on your staff account. Set it up again the next time you sign in.', ['actor' => $actor->name]),
        ]));
        TwoFactorReset::dispatch($staff->id, $actor->id);
    }
}
