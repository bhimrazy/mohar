<?php

namespace App\Events\Domains;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A free (bundled) domain was not renewed because its linked service is not active on the same due date.
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
