<?php

namespace App\Modules\Orders\Services;

use App\Contracts\Orders\OrderNumbers;
use App\Modules\Orders\Models\Order;

/**
 * Read-only order facts for other modules.
 */
class OrderDirectory implements OrderNumbers
{
    public function numbers(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return Order::query()->whereKey(array_values(array_unique($orderIds)))->pluck('number', 'id')->map(fn (mixed $number): string => (string) $number)->all();
    }
}
