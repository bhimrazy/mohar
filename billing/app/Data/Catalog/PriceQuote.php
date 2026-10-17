<?php

namespace App\Data\Catalog;

use App\Enums\BillingCycle;

/**
 * Price of a product/addon for one cycle in minor units.
 */
final readonly class PriceQuote
{
    /**
     * @param  list<array{label: string, recurring: int, setup: int}>  $breakdown  base + each config option
     */
    public function __construct(
        public string $currency,
        public BillingCycle $cycle,
        public int $recurring,
        public int $setup,
        public array $breakdown = [],
    ) {}

    public function firstPayment(): int
    {
        return $this->recurring + $this->setup;
    }
}
