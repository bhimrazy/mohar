<?php

namespace App\Modules\Platform\Actions\Staff;

use App\Contracts\Notifications\SystemMailer;
use App\Data\Notifications\SensitiveValue;
use App\Enums\SystemMailOutcome;
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
use Illuminate\Validation\ValidationException;

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
        $this->ensureLinksCanBeMailed();
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

        $this->send($invitation, $token, discardOnRefusal: true);
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
        $this->ensureLinksCanBeMailed();

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

    /**
     * The same check every account-link sender makes (AccountLinks::problem()): no invitation is created or mailed
     * when its link could not be on the site address.
     *
     * @throws ValidationException
     */
    private function ensureLinksCanBeMailed(): void
    {
        if (($problem = AccountLinks::problem()) !== null) {
            throw ValidationException::withMessages(['email' => __('Invitation not sent: the link host isn\'t your site address. :problem', ['problem' => $problem])]);
        }
    }

    /**
     * The editable staff.invitation template. When the template system refuses it (its link failed the checks),
     * nothing is sent by any other path: a security event is logged and staff see the reason. The notification is
     * used only when no template system can take it, with its link built on APP_URL.
     *
     * @throws ValidationException
     */
    private function send(StaffInvitation $invitation, string $token, bool $discardOnRefusal = false): void
    {
        $url = AccountLinks::withAppOrigin(fn (): string => route('platform.invitations.show', $token));
        $outcome = $this->mailer->send('staff.invitation', $invitation->email, [
            'auth' => ['invite_url' => new SensitiveValue($url, secret: $token), 'expires_at' => $invitation->expires_at],
        ], 'staff-invite|'.$invitation->id.'|'.StaffInvitation::hashToken($token), carriesSecret: true);

        if ($outcome === SystemMailOutcome::Refused) {
            $this->activity->log('Staff invitation not sent: its link failed the security check', $invitation, properties: ['email' => $invitation->email], event: 'security');

            if ($discardOnRefusal) {
                $invitation->delete();
            }

            throw ValidationException::withMessages(['email' => __('Invitation not sent: its link failed the security check. Check APP_URL and try again.')]);
        }

        if ($outcome === SystemMailOutcome::Unavailable) {
            Notification::route('mail', $invitation->email)->notify(new StaffInvitationNotification($invitation->load('role', 'inviter'), $token, $url));
        }
    }
}
