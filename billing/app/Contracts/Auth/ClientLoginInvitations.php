<?php

namespace App\Contracts\Auth;

use App\Enums\InvitationOutcome;
use App\Enums\InvitationPurpose;

/**
 * Sends a client login a single-use invitation link that sets its password (client-import spec §16, C3). Core binds
 * a null default (problem() says why; invite() returns Unavailable); Platform binds its invitation flow. Callers
 * outside Platform, such as the client import, reach invitations only through this contract, and ask problem()
 * rather than testing the class of the binding (ADR 0012 addendum).
 *
 * Only a never-used login is invited: no sign-in, no two-factor authentication, its password never changed since it
 * was created, no accepted invitation, not staff, still linked to the client, and the client is not closed. Anything
 * else is Skipped. A new invitation replaces the login's earlier link. The mail goes only to the login's own address,
 * never copied.
 */
interface ClientLoginInvitations
{
    /**
     * Why no invitation can be sent now (the flow is not installed, or links cannot be mailed with this
     * configuration), as a sentence for staff; null when invitations can be sent.
     */
    public function problem(): ?string;

    /**
     * @param  int|null  $actorId  the staff member who asked for it, recorded on the invitation
     */
    public function invite(int $clientId, int $userId, InvitationPurpose $purpose = InvitationPurpose::LoginAdded, ?int $actorId = null): InvitationOutcome;
}
