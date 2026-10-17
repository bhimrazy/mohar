<?php

namespace App\Events\Domains;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Contacts changed at the registrar.
 */
final class DomainContactsUpdated
{
    use Dispatchable;

    public function __construct(
        public int $domainId,
        public int $clientId,
    ) {}
}
