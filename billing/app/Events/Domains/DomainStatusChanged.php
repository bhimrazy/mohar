<?php

namespace App\Events\Domains;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Any status change made through DomainStateMachine (spec §3).
 */
final class DomainStatusChanged implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $domainId,
        public int $clientId,
        public string $from,
        public string $to,
        public ?string $reason = null,
    ) {}
}
