<?php

namespace App\Events\Domains;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Any status change made through DomainStateMachine (spec §3).
 */
final class DomainStatusChanged
{
    use Dispatchable;

    public function __construct(
        public int $domainId,
        public int $clientId,
        public string $from,
        public string $to,
        public ?string $reason = null,
    ) {}
}
