<?php

namespace Tests\Fakes\Catalog;

use App\Contracts\Services\CatalogUsage;

/**
 * In-memory CatalogUsage for Catalog tests: declare which products, option values and addons are in use.
 */
final class FakeCatalogUsage implements CatalogUsage
{
    /**
     * @param  array<int, int>  $activeCounts  product id => active services
     * @param  list<int>  $productsInUse
     * @param  list<array{0: int, 1: int}>  $valuesInUse  [option id, value id] pairs
     * @param  list<int>  $addonsInUse
     */
    public function __construct(
        public array $activeCounts = [],
        public array $productsInUse = [],
        public array $valuesInUse = [],
        public array $addonsInUse = [],
    ) {}

    public static function bind(self $fake = new self): self
    {
        app()->instance(CatalogUsage::class, $fake);

        return $fake;
    }

    public function activeServiceCountsByProduct(array $productIds): array
    {
        return array_intersect_key($this->activeCounts, array_flip($productIds));
    }

    public function productInUse(int $productId): bool
    {
        return in_array($productId, $this->productsInUse, true);
    }

    public function configOptionValueInUse(int $optionId, int $valueId): bool
    {
        return in_array([$optionId, $valueId], $this->valuesInUse, true);
    }

    public function addonInUse(int $addonId): bool
    {
        return in_array($addonId, $this->addonsInUse, true);
    }
}
