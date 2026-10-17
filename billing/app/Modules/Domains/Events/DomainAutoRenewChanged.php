<?php

namespace App\Modules\Domains\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Auto-renew was switched on or off.
 *
 * Module-local until promoted to App\Events\Domains by the shared-kernel owners.
 */
final class DomainAutoRenewChanged
{
    use Dispatchable;

    public function __construct(
        public int $domainId,
        public int $clientId,
        public bool $enabled,
    ) {}
}
