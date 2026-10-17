<?php

namespace App\Modules\Services\Services;

use App\Contracts\Services\CatalogUsage;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Catalog usage over the services tables (soft-deleted services are ignored).
 * Config option selections live in services.config_options as {"selections": {optionId: valueId}}.
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
        $path = "config_options->selections->{$optionId}";

        return Service::query()
            ->whereNotNull('config_options')
            ->where(fn (Builder $query) => $query->whereJsonContains($path, $valueId)->orWhereJsonContains($path, (string) $valueId))
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
