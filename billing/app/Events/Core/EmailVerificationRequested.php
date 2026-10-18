<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A signed email-verification link was issued for a user. Same handling rules as PasswordResetRequested.
 */
final class EmailVerificationRequested
{
    use Dispatchable;

    public function __construct(
        public int $userId,
        public string $verifyUrl,
    ) {}
}
