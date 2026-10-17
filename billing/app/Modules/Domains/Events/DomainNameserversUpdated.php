<?php

namespace App\Modules\Domains\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Nameservers changed at the registrar.
 *
 * Module-local until promoted to App\Events\Domains by the shared-kernel owners.
 */
final class DomainNameserversUpdated
{
    use Dispatchable;

    public function __construct(
        public int $domainId,
        public int $clientId,
    ) {}
}
