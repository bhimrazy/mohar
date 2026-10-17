<?php

namespace App\Events\Domains;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A transfer has been pending longer than domains.transfer_stale_days (staff notification).
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
