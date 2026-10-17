<?php

namespace App\Events\Catalog;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A product was added to the catalog.
 */
final class ProductCreated
{
    use Dispatchable;

    public function __construct(
        public int $productId,
    ) {}
}
