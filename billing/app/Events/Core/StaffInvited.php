<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A staff invitation was sent (or resent).
 */
final class StaffInvited
{
    use Dispatchable;

    public function __construct(
        public int $invitationId,
        public string $email,
        public int $roleId,
    ) {}
}
