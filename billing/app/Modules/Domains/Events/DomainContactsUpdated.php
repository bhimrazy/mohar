<?php

namespace App\Modules\Domains\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Contacts changed at the registrar.
 *
 * Module-local until promoted to App\Events\Domains by the shared-kernel owners.
 */
final class DomainContactsUpdated
{
    use Dispatchable;

    public function __construct(
        public int $domainId,
        public int $clientId,
    ) {}
}
