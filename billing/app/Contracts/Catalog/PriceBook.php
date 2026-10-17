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
     * Visible product groups with their orderable products and prices in a currency, for the storefront.
     *
     * @return list<array{id: int, name: string, slug: string, description: ?string, products: list<array{id: int, name: string, description: ?string, type: string, requires_domain: bool, in_stock: bool, pricing: list<array{cycle: string, recurring: int, setup: int}>}>}>
     */
    public function storefront(string $currency): array;

    /**
     * Configurable options for a product, for the order form.
     *
     * @return list<array{id: int, name: string, type: string, values: list<array{id: int, name: string, pricing: list<array{cycle: string, recurring: int, setup: int}>}>}>
     */
    public function configOptionsFor(int $productId, string $currency): array;

    /**
     * Addons that can be ordered with a product.
     *
     * @return list<array{id: int, name: string, description: ?string, pricing: list<array{cycle: string, recurring: int, setup: int}>}>
     */
    public function addonsFor(int $productId, string $currency): array;

    /**
     * Custom fields a client fills in when ordering the product.
     *
     * @return list<array{id: int, name: string, type: string, required: bool, options: list<string>}>
     */
    public function customFieldsFor(int $productId): array;

    /**
     * Decrement stock by one if stock control is enabled. Returns false when out of stock.
     */
    public function reserveStock(int $productId): bool;
}
