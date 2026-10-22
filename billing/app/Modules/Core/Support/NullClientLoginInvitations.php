<?php

namespace App\Modules\Core\Support;

use App\Contracts\Auth\ClientLoginInvitations;
use App\Enums\InvitationOutcome;

/**
 * Default when the Platform module is absent: no invitation flow, so nothing is sent.
 */
class NullClientLoginInvitations implements ClientLoginInvitations
{
    public function invite(int $clientId, int $userId, string $templateKey = self::TEMPLATE_DEFAULT, ?int $actorId = null): InvitationOutcome
    {
        return InvitationOutcome::Unavailable;
    }
}
