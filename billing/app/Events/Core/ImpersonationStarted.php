<?php

namespace App\Events\Core;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A staff member started signing in to the client area as a client's login.
 */
final class ImpersonationStarted implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $sessionId,
        public int $staffId,
        public int $clientId,
        public int $userId,
    ) {}
}
