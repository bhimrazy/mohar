<?php

namespace App\Events\Catalog;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A stock-controlled product reached 0 units (staff notification).
 */
final class ProductStockDepleted
{
    use Dispatchable;

    public function __construct(
        public int $productId,
    ) {}
}
