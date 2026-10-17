<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A staff member started signing in to the client area as a client's login.
 */
final class ImpersonationStarted
{
    use Dispatchable;

    public function __construct(
        public int $sessionId,
        public int $staffId,
        public int $clientId,
        public int $userId,
    ) {}
}
