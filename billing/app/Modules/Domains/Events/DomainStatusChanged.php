<?php

namespace App\Modules\Domains\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Any status change made through DomainStateMachine (spec §3).
 *
 * Module-local until promoted to App\Events\Domains by the shared-kernel owners.
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
