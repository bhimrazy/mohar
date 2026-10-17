<?php

namespace App\Modules\Core\Support;

use App\Contracts\Services\ClientServiceDirectory;

/**
 * Default when the owning module is not installed.
 */
class NullClientServiceDirectory implements ClientServiceDirectory
{
    public function forClient(int $clientId): array
    {
        return [];
    }

    public function belongsToClient(int $serviceId, int $clientId): bool
    {
        return false;
    }
}
