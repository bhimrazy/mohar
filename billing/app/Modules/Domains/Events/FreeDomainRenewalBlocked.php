<?php

namespace App\Modules\Domains\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A free (bundled) domain was not renewed because its linked service is not active on the same due date.
 *
 * Module-local until promoted to App\Events\Domains by the shared-kernel owners.
 */
final class FreeDomainRenewalBlocked
{
    use Dispatchable;

    public function __construct(
        public int $domainId,
        public int $clientId,
        public string $reason,
    ) {}
}
