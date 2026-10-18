<?php

namespace App\Events\Core;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A signed email-verification link was issued for a user. Dispatched by User instead of Laravel's VerifyEmail
 * notification whenever a listener exists. Same handling rules as PasswordResetRequested.
 */
final class EmailVerificationRequested
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $userId,
        public string $verifyUrl,
    ) {}
}
