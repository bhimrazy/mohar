<?php

namespace App\Data\Currencies;

use App\Support\Currencies\Decimal;
use Carbon\CarbonImmutable;

/**
 * Rates published by one source at one moment, as exact decimal fractions against the source's base
 * currency: `units` of the currency are worth `base` units of the base currency (ECB: 1.1253 USD = 1 EUR;
 * Nepal Rastra Bank: 100 INR = 160.075 NPR). Keeping both sides avoids inverting rates before cross-rates
 * are computed, so derived rates are exact to six decimals.
 */
final readonly class RateSnapshot
{
    /**
     * @param  array<string, array{units: string, base: string}>  $rates  ISO code => fraction (decimal strings)
     */
    public function __construct(
        public string $source,
        public string $baseCurrency,
        public array $rates,
        public CarbonImmutable $effectiveAt,
    ) {}

    /**
     * @return array{units: string, base: string}|null
     */
    public function fraction(string $code): ?array
    {
        $code = strtoupper($code);

        return $code === $this->baseCurrency ? ['units' => '1', 'base' => '1'] : ($this->rates[$code] ?? null);
    }

    /**
     * Units of $code per 1 unit of $default, derived exactly through the snapshot's base and rounded half
     * up to six decimals: (unitsCode × baseDefault) ÷ (baseCode × unitsDefault). Null when either is missing.
     */
    public function crossRate(string $default, string $code): ?string
    {
        $target = $this->fraction($code);
        $source = $this->fraction($default);

        if ($target === null || $source === null) {
            return null;
        }

        return Decimal::divide(
            Decimal::multiply($target['units'], $source['base']),
            Decimal::multiply($target['base'], $source['units']),
            6,
        );
    }
}
