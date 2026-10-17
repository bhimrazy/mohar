<?php

namespace App\Events\Catalog;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A product can no longer be ordered; existing services are unaffected.
 */
final class ProductRetired
{
    use Dispatchable;

    public function __construct(
        public int $productId,
    ) {}
}
