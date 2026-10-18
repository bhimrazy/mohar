<?php

namespace App\Modules\Platform\Actions\Staff;

use App\Events\Core\TwoFactorReset;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Services\PermissionGuard;
use App\Modules\Platform\Support\AccountMail;
use Illuminate\Validation\ValidationException;

class ResetStaffTwoFactor
{
    public function __construct(private PermissionGuard $guard, private ActivityLogger $activity, private AccountMail $mail) {}

    /**
     * Previous sign-in emails of the staff member from the last 30 days (from the audit trail).
     *
     * @return list<string>
     */
    private function recentEmails(User $staff): array
    {
        return array_values(ActivityLog::query()
            ->where('subject_type', $staff->getMorphClass())
            ->where('subject_id', $staff->id)
            ->whereIn('event', ['staff.updated', 'staff.role_changed', 'staff.disabled'])
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('id')
            ->get()
            ->map(fn (ActivityLog $entry): mixed => $entry->properties['diff']['email']['old'] ?? null)
            ->filter(fn (mixed $email): bool => is_string($email) && $email !== '')
            ->unique()
            ->all());
    }

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
        // Also tell any address the account used in the last 30 days: after an admin email change,
        // the current address may belong to whoever made that change.
        $this->mail->notice('staff.two_factor_reset', array_values(array_unique([$staff->email, ...$this->recentEmails($staff)])), ['actor' => $actor->name], 'staff:'.$staff->id, __('Your two-factor authentication was reset'), [
            __(':actor reset two-factor authentication on your staff account. Set it up again the next time you sign in.', ['actor' => $actor->name]),
        ]);
        TwoFactorReset::dispatch($staff->id, $actor->id);
    }
}
