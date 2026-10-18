<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An administrator reset a user's two-factor authentication.
 */
final class TwoFactorReset
{
    use Dispatchable;

    public function __construct(
        public int $userId,
        public int $resetByUserId,
    ) {}
}
