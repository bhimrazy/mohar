<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Integrations\ConnectionCheck;

/**
 * Capability `test_connection`: check the account's credentials ("Test connection" on the registrar page).
 * Read-only.
 */
interface TestsRegistrarConnection
{
    public function testConnection(RegistrarConfig $config): ConnectionCheck;
}
