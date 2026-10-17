<?php

namespace App\Modules\Core\Support;

use App\Contracts\Services\CatalogUsage;

/**
 * Default when the Services module is not installed: no catalog item is in use.
 */
class NullCatalogUsage implements CatalogUsage
{
    public function activeServiceCountsByProduct(array $productIds): array
    {
        return [];
    }

    public function productInUse(int $productId): bool
    {
        return false;
    }

    public function configOptionValueInUse(int $optionId, int $valueId): bool
    {
        return false;
    }

    public function addonInUse(int $addonId): bool
    {
        return false;
    }
}
