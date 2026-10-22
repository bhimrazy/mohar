<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;

/**
 * Capability `lock`: the registrar (transfer) lock.
 */
interface ManagesLock
{
    public function setLock(RegistrarConfig $config, DomainRef $domain, bool $locked): RegistrarResult;
}
