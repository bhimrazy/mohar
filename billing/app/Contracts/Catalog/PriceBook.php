<?php

namespace App\Contracts\Catalog;

use App\Data\Catalog\ModuleInputs;
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
     * A service's option selections and custom field values keyed by module key, for its provisioning driver (value
     * rules on ModuleInputs: yes/no options are "1"/"0"). Unlike the order-form reads it includes hidden options,
     * admin-only fields and retired products. Entries without a module key, and selections that no longer match a
     * value of their option, are left out; secret custom field values still count.
     *
     * @param  array<int|string, int|string|null>  $optionSelections  option id => value id, or the quantity
     * @param  array<int|string, mixed>  $customFieldValues  field id => value
     */
    public function moduleInputs(int $productId, array $optionSelections, array $customFieldValues): ModuleInputs;

    /**
     * Every product provisioned by the driver, hidden ones included and retired ones left out, in id order (account
     * import matching and package warnings).
     *
     * @return list<ProductSummary>
     */
    public function productsForDriver(string $driverKey): array;

    /**
     * Price of a product with config options; null if not offered in that cycle/currency, the product is
     * retired/unknown, or a selected option/value is invalid. MUST NOT throw for invalid selections.
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
     * Extra keys MAY be present: 'regex' (?string, PCRE incl. delimiters), 'admin_only' (bool), 'description' (?string).
     *
     * @return list<array{id: int, name: string, type: string, required: bool, options: list<string>}>
     */
    public function customFieldsFor(int $productId): array;

    /**
     * Decrement stock by one if stock control is enabled. Returns false when out of stock.
     */
    public function reserveStock(int $productId): bool;

    /**
     * Return previously reserved stock (order cancelled / fraud). Idempotency is the caller's job:
     * call it once per reservation. No-op when stock control is disabled.
     */
    public function releaseStock(int $productId, int $quantity = 1): void;

    public function productBySlug(string $slug): ?ProductSummary;
}
