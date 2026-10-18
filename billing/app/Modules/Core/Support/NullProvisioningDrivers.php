<?php

namespace App\Modules\Core\Support;

use App\Contracts\Services\ProvisioningDrivers;
use App\Data\Services\RemoteOptions;

/**
 * Default when the owning module is not installed.
 */
class NullProvisioningDrivers implements ProvisioningDrivers
{
    public function all(): array
    {
        return [];
    }

    public function exists(string $key): bool
    {
        return false;
    }

    public function remoteOptions(string $driverKey, string $fieldKey, array $serverIds, bool $refresh = false): RemoteOptions
    {
        return new RemoteOptions;
    }
}
