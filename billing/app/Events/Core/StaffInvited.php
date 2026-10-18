<?php

namespace App\Events\Core;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A staff invitation was sent (or resent).
 */
final class StaffInvited
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $invitationId,
        public string $email,
        public int $roleId,
    ) {}
}
