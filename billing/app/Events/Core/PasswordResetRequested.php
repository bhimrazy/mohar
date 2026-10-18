<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A password reset link was issued for a user (client or staff). Carries the one-time URL so the
 * Notifications module can mail it; listeners must never persist it unredacted.
 */
final class PasswordResetRequested
{
    use Dispatchable;

    public function __construct(
        public int $userId,
        public string $resetUrl,
        public string $expiresAt,
    ) {}
}
