<?php

namespace App\Modules\Platform\Actions\Clients;

use App\Models\User;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\LoginSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A new client login accepts its invitation: the password is set once, before the link expires.
 */
class AcceptClientInvitation
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @throws ValidationException when the invitation expired or was used
     */
    public function handle(ClientInvitation $invitation, #[\SensitiveParameter] string $password): User
    {
        $user = DB::transaction(function () use ($invitation, $password): User {
            // Single use: the row is locked and marked accepted in the same transaction as the password. The login row
            // is locked first (as PlatformClientLoginInvitations::invite() does) and checked as locked, so an email,
            // password or two-factor change committed meanwhile is seen.
            $user = User::query()->lockForUpdate()->findOrFail($invitation->user_id);
            $locked = ClientInvitation::query()->lockForUpdate()->whereKey($invitation->id)->where('user_id', $user->id)->firstOrFail();
            $locked->setRelation('user', $user);

            if ($locked->state() !== 'valid') {
                throw ValidationException::withMessages(['token' => self::refusal($locked->state())]);
            }

            $locked->update(['accepted_at' => now()]);
            // The link proves the inbox it was mailed to, and only that one.
            $verified = mb_strtolower($user->email) === mb_strtolower($locked->email);
            // Setting the password is a reset: any other session or remember-me cookie of the login ends.
            LoginSessions::rotateRememberToken($user);
            $user->forceFill(['password' => $password, 'email_verified_at' => $user->email_verified_at ?? ($verified ? now() : null)])->save();
            LoginSessions::revoke($user);

            return $user;
        });

        $this->activity->log('Client login accepted its invitation', $user, $invitation->client_id, ['email' => $user->email], 'client.invitation_accepted', $user);

        return $user;
    }

    public static function refusal(string $state): string
    {
        return match ($state) {
            'used' => __('This invitation has already been used. Log in with your email and password.'),
            'expired' => __('This invitation has expired. Ask the account owner or our team to send a new one.'),
            default => __('This invitation is no longer valid; ask for a new one.'),
        };
    }
}
