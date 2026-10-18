<?php

namespace App\Events\Catalog;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A product's details changed (search index, caches).
 */
final class ProductUpdated
{
    use Dispatchable;

    public function __construct(
        public int $productId,
    ) {}
}
