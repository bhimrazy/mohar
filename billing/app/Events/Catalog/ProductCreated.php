<?php

namespace App\Events\Catalog;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A product was added to the catalog.
 */
final class ProductCreated
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $productId,
    ) {}
}
