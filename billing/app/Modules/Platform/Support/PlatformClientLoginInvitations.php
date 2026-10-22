<?php

namespace App\Modules\Platform\Support;

use App\Contracts\Auth\ClientLoginInvitations;
use App\Enums\InvitationOutcome;
use App\Enums\InvitationPurpose;
use App\Enums\SystemMailOutcome;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Support\AccountLinks;
use App\Modules\Platform\Actions\Clients\ManageClientLogins;
use Illuminate\Support\Facades\DB;

/**
 * Platform's invitation flow behind the kernel contract (client-import spec §16, C3): the same single-use link,
 * expiry and accept page as an invitation staff send from a client's logins, with the template the caller names.
 */
class PlatformClientLoginInvitations implements ClientLoginInvitations
{
    public function __construct(private ManageClientLogins $logins) {}

    /**
     * The editable template of each purpose.
     */
    public static function template(InvitationPurpose $purpose): string
    {
        return match ($purpose) {
            InvitationPurpose::Import => 'client.migration_invitation',
            default => 'auth.user_invitation',
        };
    }

    public function problem(): ?string
    {
        $problem = AccountLinks::problem();

        if ($problem === null) {
            return null;
        }

        return __('Invitations are held until the link host is your site address. :problem', ['problem' => $problem]);
    }

    /**
     * In one transaction, so a failure part way leaves no invitation row behind for a link that was never mailed.
     */
    public function invite(int $clientId, int $userId, InvitationPurpose $purpose = InvitationPurpose::LoginAdded, ?int $actorId = null): InvitationOutcome
    {
        return DB::transaction(function () use ($clientId, $userId, $purpose, $actorId): InvitationOutcome {
            $client = Client::query()->find($clientId);
            $user = User::query()->lockForUpdate()->find($userId);

            if ($client === null || $user === null || ! self::neverUsed($user) || $client->status === 'closed'
                || ! $client->users()->whereKey($user->id)->exists()
                || ClientInvitation::query()->where('client_id', $client->id)->where('user_id', $user->id)->whereNotNull('accepted_at')->exists()) {
                return InvitationOutcome::Skipped;
            }

            if ($this->problem() !== null) {
                return InvitationOutcome::Refused;
            }

            return match ($this->logins->mailInvitation($user, $client, self::template($purpose), $actorId)) {
                SystemMailOutcome::Refused => InvitationOutcome::Refused,
                SystemMailOutcome::Suppressed => InvitationOutcome::Held,
                default => InvitationOutcome::Sent,
            };
        });
    }

    /**
     * A client login nobody has used: active, never signed in, no two-factor authentication, and its password never
     * changed since the login was created (the random one it was created with, which nobody knows). The client
     * import lists logins with the same rule in SQL (Migration's ImportInvitations::candidates()); a test checks both
     * agree.
     */
    public static function neverUsed(User $user): bool
    {
        return ! $user->is_staff && $user->is_active && $user->last_login_at === null
            && $user->two_factor_secret === null && $user->two_factor_confirmed_at === null
            && ($user->password_changed_at === null || $user->created_at === null || $user->password_changed_at->lessThanOrEqualTo($user->created_at));
    }
}
