<?php

namespace App\Modules\Catalog\Services;

use App\Enums\BillingCycle;
use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionGroup;
use App\Modules\Catalog\Models\ConfigOptionValue;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CyclePicker;

/**
 * Prevents the "unpriced option value is free" revenue leak: every offered option value must have a price
 * for every currency × cycle the product sells.
 */
class ConfigOptionPricingGuard
{
    /**
     * Human-readable list of option values lacking a price for one of the product's enabled cycles.
     *
     * @param  list<array{currency: string, cycle: string}>  $enabledProductPrices
     * @param  list<int>  $groupIds
     * @return list<string>
     */
    public function missingPrices(array $enabledProductPrices, array $groupIds): array
    {
        if ($enabledProductPrices === [] || $groupIds === []) {
            return [];
        }

        $groups = ConfigOptionGroup::query()
            ->whereKey($groupIds)
            ->with(['options' => fn ($query) => $query->where('is_hidden', false), 'options.values' => fn ($query) => $query->where('is_hidden', false), 'options.values.prices'])
            ->get();

        $missing = [];

        foreach ($groups as $group) {
            foreach ($group->options as $option) {
                foreach ($option->values as $value) {
                    foreach ($enabledProductPrices as $row) {
                        $cycle = BillingCycle::from($row['cycle']);

                        if ($value->priceFor($row['currency'], $cycle) === null) {
                            $missing[] = $this->describe($option, $value, $row['currency'], $cycle);
                        }
                    }
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * Enabled (currency, cycle) pairs a product sells, from its loaded or queried prices.
     *
     * @return list<array{currency: string, cycle: string}>
     */
    public function enabledPairsOf(Product $product): array
    {
        $pairs = [];

        foreach ($product->prices()->where('enabled', true)->orderBy('id')->get() as $price) {
            if (in_array($price->cycle, $product->payment_type->cycles(), true)) {
                $pairs[] = ['currency' => $price->currency, 'cycle' => $price->cycle->value];
            }
        }

        return $pairs;
    }

    private function describe(ConfigOption $option, ConfigOptionValue $value, string $currency, BillingCycle $cycle): string
    {
        return sprintf('%s › %s (%s, %s)', $option->name, $value->label, strtoupper($currency), strtolower(CyclePicker::label($cycle)));
    }
}
