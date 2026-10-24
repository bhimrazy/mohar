<?php

namespace App\Modules\Orders\Services;

use App\Modules\Core\Models\Currency;

/**
 * Converts amounts defined in the default currency (promotion values, minimum cart amounts) into a
 * cart currency at the current exchange rate and the currency's price rounding rule (the same rule as converted catalog prices).
 */
class CurrencyConverter
{
    /** @var array<string, Currency|null> */
    private array $currencies = [];

    public function fromDefault(int $amount, string $currency): int
    {
        $default = Currency::default();

        if (strtoupper($currency) === $default->code) {
            return $amount;
        }

        $target = $this->currencies[$currency] ??= Currency::query()->where('code', strtoupper($currency))->first();

        return $target === null ? $amount : $target->priceFromDefault($amount, $default);
    }
}
