<?php

namespace App\Modules\Catalog\Models\Concerns;

use App\Enums\BillingCycle;
use App\Modules\Catalog\Models\Price;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Prices per currency × billing cycle stored in the polymorphic `prices` table.
 */
trait HasPrices
{
    /**
     * @return MorphMany<Price, $this>
     */
    public function prices(): MorphMany
    {
        return $this->morphMany(Price::class, 'priceable');
    }

    /**
     * The enabled price row for a currency and cycle from the loaded `prices` relation.
     */
    public function priceFor(string $currency, BillingCycle $cycle): ?Price
    {
        $currency = strtoupper($currency);

        return $this->prices->first(fn (Price $price): bool => $price->enabled && $price->currency === $currency && $price->cycle === $cycle);
    }

    /**
     * Enabled price rows in a currency, ordered from the shortest to the longest cycle.
     *
     * @return list<Price>
     */
    public function enabledPricesIn(string $currency): array
    {
        $currency = strtoupper($currency);
        $order = array_flip(array_map(fn (BillingCycle $cycle): string => $cycle->value, BillingCycle::cases()));

        return array_values($this->prices
            ->filter(fn (Price $price): bool => $price->enabled && $price->currency === $currency)
            ->sortBy(fn (Price $price): int => $order[$price->cycle->value])
            ->all());
    }
}
