<?php

namespace App\Contracts\Services\Provisioning;

use App\Data\Integrations\ConnectionCheck;
use App\Data\Services\Provisioning\ServerConnection;

/**
 * Capability: check a server's hostname and credentials ("Test connection" on the server form). Read-only: it
 * never creates or changes an account.
 */
interface TestsServerConnection
{
    public function testConnection(ServerConnection $server): ConnectionCheck;
}
