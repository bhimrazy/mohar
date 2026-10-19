<?php

namespace App\Events\Catalog;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A stock-controlled product reached 0 units (staff notification).
 */
final class ProductStockDepleted implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $productId,
    ) {}
}
