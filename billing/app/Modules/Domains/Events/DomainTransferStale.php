<?php

namespace App\Modules\Domains\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A transfer has been pending longer than domains.transfer_stale_days (staff notification).
 *
 * Module-local until promoted to App\Events\Domains by the shared-kernel owners.
 */
final class DomainTransferStale
{
    use Dispatchable;

    public function __construct(
        public int $domainId,
        public int $clientId,
        public int $days,
    ) {}
}
