<?php

namespace App\Events\Domains;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Auto-renew was switched on or off.
 */
final class DomainAutoRenewChanged
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $domainId,
        public int $clientId,
        public bool $enabled,
    ) {}
}
