<?php

namespace App\Modules\Core\Support;

use App\Contracts\Auth\ClientLoginInvitations;
use App\Enums\InvitationOutcome;
use App\Enums\InvitationPurpose;

/**
 * Default when the Platform module is absent: no invitation flow, so nothing is sent.
 */
class NullClientLoginInvitations implements ClientLoginInvitations
{
    public function problem(): ?string
    {
        return __('Invitations are not available: the module that sends them is not installed.');
    }

    public function invite(int $clientId, int $userId, InvitationPurpose $purpose = InvitationPurpose::LoginAdded, ?int $actorId = null): InvitationOutcome
    {
        return InvitationOutcome::Unavailable;
    }
}
