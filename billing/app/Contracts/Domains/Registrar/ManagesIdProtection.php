<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;

/**
 * Capability `id_protection`: WHOIS privacy.
 */
interface ManagesIdProtection
{
    public function setIdProtection(RegistrarConfig $config, DomainRef $domain, bool $enabled): RegistrarResult;
}
