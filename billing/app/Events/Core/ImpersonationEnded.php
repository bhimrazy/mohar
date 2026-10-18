<?php

namespace App\Events\Core;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sign-in-as-client session ended (reason: returned, expired, logout).
 */
final class ImpersonationEnded implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $sessionId,
        public int $staffId,
        public int $clientId,
        public string $reason,
    ) {}
}
