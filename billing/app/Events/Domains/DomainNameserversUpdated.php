<?php

namespace App\Events\Domains;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Nameservers changed at the registrar.
 */
final class DomainNameserversUpdated
{
    use Dispatchable;

    public function __construct(
        public int $domainId,
        public int $clientId,
    ) {}
}
