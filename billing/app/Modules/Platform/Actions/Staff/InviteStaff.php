<?php

namespace App\Modules\Platform\Actions\Staff;

use App\Models\User;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Models\StaffInvitation;
use App\Modules\Platform\Notifications\StaffInvitationNotification;
use App\Modules\Platform\Services\PermissionGuard;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InviteStaff
{
    public function __construct(private PermissionGuard $guard, private ActivityLogger $activity) {}

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
            'expires_at' => now()->addDays((int) config('platform.invitation_expiry_days', 7)),
            'invited_by' => $actor->id,
        ]);

        $this->send($invitation, $token);
        $this->activity->log("Staff invitation sent to {$email}", $invitation, properties: ['role' => $role->name], event: 'staff.invited');

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
            'expires_at' => now()->addDays((int) config('platform.invitation_expiry_days', 7)),
        ]);

        $this->send($invitation, $token);
        $this->activity->log("Staff invitation resent to {$invitation->email}", $invitation, event: 'staff.invitation_resent');

        return $invitation;
    }

    private function send(StaffInvitation $invitation, string $token): void
    {
        Notification::route('mail', $invitation->email)->notify(new StaffInvitationNotification($invitation->load('role', 'inviter'), $token));
    }
}
