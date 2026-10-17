<?php

namespace App\Modules\Domains\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sync reported that the domain was transferred to another registrar.
 *
 * Module-local until promoted to App\Events\Domains by the shared-kernel owners.
 */
final class DomainTransferredAway
{
    use Dispatchable;

    public function __construct(
        public int $domainId,
        public int $clientId,
    ) {}
}
