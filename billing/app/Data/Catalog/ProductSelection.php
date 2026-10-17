<?php

namespace App\Data\Catalog;

use App\Enums\BillingCycle;

final readonly class ProductSelection
{
    /**
     * @param  array<int, int>  $configOptions  option id => selected value id (or quantity for quantity options)
     * @param  int|null  $clientId  when given, the client's group discount is applied to the quote
     */
    public function __construct(
        public int $productId,
        public BillingCycle $cycle,
        public string $currency,
        public array $configOptions = [],
        public ?int $clientId = null,
    ) {}
}
