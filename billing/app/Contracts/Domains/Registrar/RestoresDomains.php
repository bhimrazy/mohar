<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;

/**
 * Capability `restore`: bring a domain back from redemption, renewing it for $years when the registry requires it.
 * A mutating command with the same outcome rules as renew.
 */
interface RestoresDomains
{
    public function restore(RegistrarConfig $config, DomainRef $domain, int $years): RegistrarResult;
}
