<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Domains\Registrar\AvailabilityResult;
use App\Data\Domains\Registrar\RegistrarConfig;

/**
 * Capability `availability`: look names up for the public domain search. Read-only and called often; the host caches.
 */
interface ChecksAvailability
{
    /**
     * @param  list<string>  $tlds  extensions with their leading dot
     * @return list<AvailabilityResult> one per extension, `unknown` when the registry did not answer
     */
    public function checkAvailability(RegistrarConfig $config, string $sld, array $tlds): array;
}
