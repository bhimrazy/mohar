<?php

namespace App\Contracts\Services;

/**
 * How catalog items are used by client services (delete guards, list counts).
 * Implemented by the Services module; Core binds a null implementation (nothing in use).
 */
interface CatalogUsage
{
    /**
     * Active services per product id (ids with no active services may be omitted).
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    public function activeServiceCountsByProduct(array $productIds): array;

    /**
     * Whether any non-deleted service (any status) uses the product.
     */
    public function productInUse(int $productId): bool;

    /**
     * Whether any service has this configurable option value selected.
     */
    public function configOptionValueInUse(int $optionId, int $valueId): bool;

    /**
     * Whether any service addon uses the catalog addon.
     */
    public function addonInUse(int $addonId): bool;
}
