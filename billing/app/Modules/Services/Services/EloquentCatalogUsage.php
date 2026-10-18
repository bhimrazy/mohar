<?php

namespace App\Modules\Services\Services;

use App\Contracts\Services\CatalogUsage;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Models\ServiceConfigOption;

/**
 * Catalog usage over the services tables (soft-deleted services are ignored).
 * Config option selections are read from the normalized service_config_options table.
 */
class EloquentCatalogUsage implements CatalogUsage
{
    public function activeServiceCountsByProduct(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return Service::query()
            ->whereIn('product_id', $productIds)
            ->where('status', ServiceStatus::Active->value)
            ->groupBy('product_id')
            ->selectRaw('product_id, count(*) as aggregate')
            ->pluck('aggregate', 'product_id')
            ->mapWithKeys(fn ($count, $productId): array => [(int) $productId => (int) $count])
            ->all();
    }

    public function productInUse(int $productId): bool
    {
        return Service::query()->where('product_id', $productId)->exists();
    }

    public function configOptionValueInUse(int $optionId, int $valueId): bool
    {
        return ServiceConfigOption::query()
            ->where('option_id', $optionId)
            ->where('value_id', $valueId)
            ->whereIn('service_id', Service::query()->select('id'))
            ->exists();
    }

    public function addonInUse(int $addonId): bool
    {
        return ServiceAddon::query()
            ->where('addon_id', $addonId)
            ->whereIn('service_id', Service::query()->select('id'))
            ->exists();
    }
}
