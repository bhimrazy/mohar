<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;

/**
 * Capability `nameservers`.
 */
interface ManagesNameservers
{
    /**
     * @param  list<string>  $nameservers  2 to 5 hostnames, normalised by the host
     */
    public function setNameservers(RegistrarConfig $config, DomainRef $domain, array $nameservers): RegistrarResult;
}
