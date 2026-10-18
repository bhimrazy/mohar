<?php

namespace App\Events\Domains;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Nameservers changed at the registrar.
 */
final class DomainNameserversUpdated
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $domainId,
        public int $clientId,
    ) {}
}
