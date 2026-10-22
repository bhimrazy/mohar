<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\RegistrationRequest;
use App\Data\Domains\Registrar\TransferSyncResult;

/**
 * Capabilities `transfer` and `transfer_sync`: transfer a domain in, then follow it until it completes or fails.
 */
interface TransfersDomains
{
    /**
     * Usually pending. $request->eppCode carries the transfer code.
     */
    public function transfer(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult;

    public function transferSync(RegistrarConfig $config, DomainRef $domain): TransferSyncResult;
}
