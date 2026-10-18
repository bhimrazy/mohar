<?php

namespace App\Modules\Platform\Actions\Staff;

use App\Contracts\Notifications\SystemMailer;
use App\Data\Notifications\SensitiveValue;
use App\Events\Core\StaffInvited;
use App\Models\User;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\AccountLinks;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Models\StaffInvitation;
use App\Modules\Platform\Notifications\StaffInvitationNotification;
use App\Modules\Platform\Services\PermissionGuard;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InviteStaff
{
    public function __construct(private PermissionGuard $guard, private ActivityLogger $activity, private Settings $settings, private SystemMailer $mailer) {}

    /**
     * Invitation validity from the "Staff invitation validity" setting (falls back to config).
     */
    public function expiryDays(): int
    {
        return max(1, (int) $this->settings->get('platform.invitation_expiry_days', 7));
    }

    public function handle(User $actor, string $email, ?string $name, Role $role): StaffInvitation
    {
        $this->guard->ensureCanGrant($actor, $role->permissions, 'role_id');
        $email = mb_strtolower(trim($email));

        StaffInvitation::query()->pending()->where('email', $email)->delete();

        $token = Str::random(48);
        $invitation = StaffInvitation::query()->create([
            'email' => $email,
            'name' => $name,
            'role_id' => $role->id,
            'token_hash' => StaffInvitation::hashToken($token),
            'expires_at' => now()->addDays($this->expiryDays()),
            'invited_by' => $actor->id,
        ]);

        $this->send($invitation, $token);
        $this->activity->log('Staff invitation sent', $invitation, properties: ['email' => $email, 'role' => $role->name], event: 'staff.invited');
        StaffInvited::dispatch($invitation->id, $invitation->email, $role->id);

        return $invitation;
    }

    /**
     * Issue a fresh token and expiry for an existing (possibly expired) invitation.
     */
    public function resend(User $actor, StaffInvitation $invitation): StaffInvitation
    {
        $this->guard->ensureCanGrant($actor, $invitation->role->permissions, 'role_id');

        $token = Str::random(48);
        $invitation->update([
            'token_hash' => StaffInvitation::hashToken($token),
            'expires_at' => now()->addDays($this->expiryDays()),
        ]);

        $this->send($invitation, $token);
        $this->activity->log('Staff invitation resent', $invitation, properties: ['email' => $invitation->email], event: 'staff.invitation_resent');
        StaffInvited::dispatch($invitation->id, $invitation->email, $invitation->role_id);

        return $invitation;
    }

    private function send(StaffInvitation $invitation, string $token): void
    {
        // The editable staff.invitation template; the notification is the fallback when Notifications is absent.
        $sent = $this->mailer->send('staff.invitation', [$invitation->email], [
            'auth' => ['invite_url' => new SensitiveValue(AccountLinks::withAppOrigin(fn (): string => route('platform.invitations.show', $token)), secret: $token), 'expires_at' => $invitation->expires_at],
        ], 'staff-invite|'.$invitation->id.'|'.StaffInvitation::hashToken($token), carriesSecret: true);

        if (! $sent) {
            Notification::route('mail', $invitation->email)->notify(new StaffInvitationNotification($invitation->load('role', 'inviter'), $token));
        }
    }
}
