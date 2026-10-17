<?php

namespace App\Contracts\Catalog;

use App\Data\Catalog\PriceQuote;
use App\Data\Catalog\ProductSelection;
use App\Data\Catalog\ProductSummary;
use App\Enums\BillingCycle;

/**
 * Implemented by the Catalog module.
 */
interface PriceBook
{
    public function product(int $productId): ?ProductSummary;

    /**
     * Price of a product with config options; null if not offered in that cycle/currency.
     */
    public function quote(ProductSelection $selection): ?PriceQuote;

    public function addonQuote(int $addonId, BillingCycle $cycle, string $currency): ?PriceQuote;

    /**
     * @return list<BillingCycle>
     */
    public function availableCycles(int $productId, string $currency): array;

    /**
     * Whether $toProductId is an allowed upgrade/downgrade target of $fromProductId.
     */
    public function canUpgrade(int $fromProductId, int $toProductId): bool;

    /**
     * Decrement stock by one if stock control is enabled. Returns false when out of stock.
     */
    public function reserveStock(int $productId): bool;
}
