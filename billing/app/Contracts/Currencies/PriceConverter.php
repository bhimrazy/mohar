<?php

namespace App\Contracts\Currencies;

use App\Data\Currencies\PriceConversionPreview;

/**
 * A module's "converted prices": price rows in other currencies derived from the default-currency price ×
 * exchange rate, with each currency's buffer and rounding rule (Currency::priceFromDefault()). Rows staff
 * set manually are never touched. Existing services and domains keep their stored recurring amounts.
 * Register implementations with app(PriceConverters::class)->register(MyConverter::class).
 */
interface PriceConverter
{
    /** Stable key, e.g. "catalog". */
    public function key(): string;

    /** What is converted, for staff, e.g. "Products, addons and configurable options". */
    public function label(): string;

    /** Whether the module's own setting converts prices automatically after rates change. */
    public function automatic(): bool;

    /**
     * Whether any price is stored in this currency (a currency in use by prices cannot be deleted).
     */
    public function usesCurrency(string $code): bool;

    /**
     * What apply() would change right now, without writing anything.
     */
    public function preview(int $samples = 10): PriceConversionPreview;

    /**
     * Write the converted prices.
     *
     * @return int number of price rows created or changed
     */
    public function apply(): int;
}
