<?php

namespace App\Events\Core;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A password reset link was issued for a user (client or staff). Dispatched by User instead of Laravel's
 * ResetPassword notification whenever a listener exists (Notifications mails it from an editable template).
 * Carries the one-time token and URL; listeners must never persist them unredacted.
 */
final class PasswordResetRequested
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $userId,
        public string $resetUrl,
        public string $expiresAt,
        /** The raw broker token, for a listener that falls back to Laravel's ResetPassword notification. */
        public string $token = '',
    ) {}
}
