<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sign-in-as-client session ended (reason: returned, expired, logout).
 */
final class ImpersonationEnded
{
    use Dispatchable;

    public function __construct(
        public int $sessionId,
        public int $staffId,
        public int $clientId,
        public string $reason,
    ) {}
}
