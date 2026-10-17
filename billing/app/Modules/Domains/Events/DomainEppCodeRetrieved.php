<?php

namespace App\Modules\Domains\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An EPP/transfer code was retrieved; actor is "client" or "staff".
 *
 * Module-local until promoted to App\Events\Domains by the shared-kernel owners.
 */
final class DomainEppCodeRetrieved
{
    use Dispatchable;

    public function __construct(
        public int $domainId,
        public int $clientId,
        public string $actor,
        public ?int $userId,
        public bool $emailed,
    ) {}
}
