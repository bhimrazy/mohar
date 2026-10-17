<?php

namespace App\Modules\Billing\Services\Tax;

use App\Modules\Billing\Enums\TaxExemptReason;

/**
 * The tax context of one document: up to two levels with rates in thousandths of a percent (20% = 20000).
 */
final readonly class TaxRates
{
    public function __construct(
        public ?string $name1 = null,
        public int $rate1 = 0,
        public ?string $name2 = null,
        public int $rate2 = 0,
        public bool $compound = false,
        public bool $inclusive = false,
        public bool $perInvoice = false,
        public string $rounding = 'half_up',
        public ?TaxExemptReason $exemptReason = null,
    ) {}

    public static function none(?TaxExemptReason $reason = null): self
    {
        return new self(exemptReason: $reason);
    }

    public function hasTax(): bool
    {
        return $this->rate1 > 0 || $this->rate2 > 0;
    }

    /**
     * Percent as a decimal string for storage, e.g. 20000 → "20.000".
     */
    public static function toPercent(int $milli): string
    {
        return number_format($milli / 1000, 3, '.', '');
    }

    /**
     * Percent (e.g. "20.000", 17.5) → thousandths of a percent.
     */
    public static function toMilli(string|int|float $percent): int
    {
        return (int) round(((float) $percent) * 1000);
    }
}
