<?php

namespace App\Modules\Core\Support;

use App\Contracts\Billing\GatewayDirectory;

/**
 * Default when the owning module is not installed.
 */
class NullGatewayDirectory implements GatewayDirectory
{
    public function available(string $currency): array
    {
        return [];
    }
}
