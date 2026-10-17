<?php

namespace App\Modules\Core\Support;

use App\Contracts\Services\ProvisioningDrivers;

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
}
