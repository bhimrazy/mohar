<?php

namespace App\Modules\Core\Support;

use App\Contracts\Orders\OrderNumbers;

/**
 * Default when the owning module is not installed: no numbers are known.
 */
class NullOrderNumbers implements OrderNumbers
{
    public function numbers(array $orderIds): array
    {
        return [];
    }
}
