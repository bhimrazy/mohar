<?php

namespace App\Events\Catalog;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A product's details changed (search index, caches).
 */
final class ProductUpdated
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $productId,
    ) {}
}
