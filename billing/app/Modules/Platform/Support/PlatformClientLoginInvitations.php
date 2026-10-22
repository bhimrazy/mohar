<?php

namespace App\Modules\Platform\Support;

use App\Contracts\Auth\ClientLoginInvitations;
use App\Enums\InvitationOutcome;
use App\Enums\SystemMailOutcome;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Support\AccountLinks;
use App\Modules\Platform\Actions\Clients\ManageClientLogins;
use InvalidArgumentException;

/**
 * Platform's invitation flow behind the kernel contract (client-import spec §16, C3): the same single-use link,
 * expiry and accept page as an invitation staff send from a client's logins, with the template the caller names.
 */
class PlatformClientLoginInvitations implements ClientLoginInvitations
{
    public function __construct(private ManageClientLogins $logins) {}

    public function invite(int $clientId, int $userId, string $templateKey = self::TEMPLATE_DEFAULT, ?int $actorId = null): InvitationOutcome
    {
        if (! in_array($templateKey, [self::TEMPLATE_DEFAULT, self::TEMPLATE_MIGRATION], true)) {
            throw new InvalidArgumentException("[{$templateKey}] is not an invitation template.");
        }

        $client = Client::query()->find($clientId);
        $user = User::query()->find($userId);

        if ($client === null || $user === null || ! self::neverUsed($user) || $client->status === 'closed'
            || ! $client->users()->whereKey($user->id)->exists()
            || ClientInvitation::query()->where('client_id', $client->id)->where('user_id', $user->id)->whereNotNull('accepted_at')->exists()) {
            return InvitationOutcome::Skipped;
        }

        if (AccountLinks::problem() !== null) {
            return InvitationOutcome::Refused;
        }

        return $this->logins->mailInvitation($user, $client, $templateKey, $actorId) === SystemMailOutcome::Refused
            ? InvitationOutcome::Refused
            : InvitationOutcome::Sent;
    }

    /**
     * A client login nobody has used: active, never signed in, no two-factor authentication, and its password never
     * changed since the login was created (the random one it was created with, which nobody knows).
     */
    public static function neverUsed(User $user): bool
    {
        return ! $user->is_staff && $user->is_active && $user->last_login_at === null
            && $user->two_factor_secret === null && $user->two_factor_confirmed_at === null
            && ($user->password_changed_at === null || $user->created_at === null || $user->password_changed_at->lessThanOrEqualTo($user->created_at));
    }
}
