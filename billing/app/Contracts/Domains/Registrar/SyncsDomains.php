<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\SyncResult;

/**
 * Capability `sync`: the scheduled status and expiry check. Read-only.
 */
interface SyncsDomains
{
    public function sync(RegistrarConfig $config, DomainRef $domain): SyncResult;
}
