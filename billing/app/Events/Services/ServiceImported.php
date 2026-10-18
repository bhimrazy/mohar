<?php

namespace App\Events\Services;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A service was created from an account that already existed on a server (account import), not ordered. No invoice,
 * welcome mail or module command follows. Dispatched after the import row commits.
 */
final class ServiceImported
{
    use Dispatchable;

    /**
     * @param  string  $driver  the provisioning driver key
     * @param  string  $source  where the account came from, e.g. "server" (ListsAccounts import) or "whmcs"
     */
    public function __construct(
        public int $serviceId,
        public int $clientId,
        public int $serverId,
        public string $driver,
        public string $source,
    ) {}
}
