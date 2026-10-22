<?php

namespace App\Contracts\Auth;

use App\Enums\InvitationOutcome;

/**
 * Sends a client login a single-use invitation link that sets its password (client-import spec §16, C3). Core binds
 * a null default (always Unavailable); Platform binds its invitation flow. Callers outside Platform, such as the
 * client import, reach invitations only through this contract.
 *
 * Only a never-used login is invited: no sign-in, no two-factor authentication, no accepted invitation, not staff,
 * still linked to the client, and the client is not closed. Anything else is Skipped. A new invitation replaces the
 * login's earlier link. The mail goes only to the login's own address, never copied.
 */
interface ClientLoginInvitations
{
    /** The invitation staff send from a client's logins. */
    public const TEMPLATE_DEFAULT = 'auth.user_invitation';

    /** The invitation for logins created by a client import ("we have moved to a new client area"). */
    public const TEMPLATE_MIGRATION = 'client.migration_invitation';

    /**
     * @param  string  $templateKey  one of the TEMPLATE_* constants
     * @param  int|null  $actorId  the staff member who asked for it, recorded on the invitation
     */
    public function invite(int $clientId, int $userId, string $templateKey = self::TEMPLATE_DEFAULT, ?int $actorId = null): InvitationOutcome;
}
