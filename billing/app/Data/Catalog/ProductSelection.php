<?php

namespace App\Data\Catalog;

use App\Enums\BillingCycle;

final readonly class ProductSelection
{
    /**
     * @param  array<int, int>  $configOptions  option id => selected value id (or quantity for quantity options)
     */
    public function __construct(
        public int $productId,
        public BillingCycle $cycle,
        public string $currency,
        public array $configOptions = [],
    ) {}
}
