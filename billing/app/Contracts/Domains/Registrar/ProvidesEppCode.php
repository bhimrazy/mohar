<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\EppCodeResult;
use App\Data\Domains\Registrar\RegistrarConfig;

/**
 * Capability `epp`: the transfer-out code, or `emailed` when the registrar sends it to the registrant. The host
 * shows a returned code once and never stores it.
 */
interface ProvidesEppCode
{
    public function getEppCode(RegistrarConfig $config, DomainRef $domain): EppCodeResult;
}
