<?php

namespace App\Modules\Catalog\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only view of how catalog items are used by client services (delete guards, list counts).
 *
 * Interim adapter: there is no shared contract for "services using product X" yet, so this reads the
 * Services module's tables when they exist and reports "unused" otherwise. Replace with a contract call
 * (e.g. ServiceRegistry::countByProduct) once the shared kernel offers one; nothing else depends on it.
 */
class ServiceUsage
{
    public const SERVICES_TABLE = 'services';

    public const SERVICE_ADDONS_TABLE = 'service_addons';

    /** @var array<string, bool> */
    private array $tables = [];

    /**
     * Active services per product.
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    public function activeCountsByProduct(array $productIds): array
    {
        if ($productIds === [] || ! $this->hasColumns(self::SERVICES_TABLE, ['product_id', 'status'])) {
            return [];
        }

        return $this->services()
            ->whereIn('product_id', $productIds)
            ->where('status', 'active')
            ->groupBy('product_id')
            ->selectRaw('product_id, count(*) as aggregate')
            ->pluck('aggregate', 'product_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    public function productHasServices(int $productId): bool
    {
        return $this->hasColumns(self::SERVICES_TABLE, ['product_id'])
            && $this->services()->where('product_id', $productId)->exists();
    }

    /**
     * Whether any service has this value selected for the option (services.config_options = {option_id: value_id}).
     */
    public function configValueInUse(int $optionId, int $valueId): bool
    {
        if (! $this->hasColumns(self::SERVICES_TABLE, ['config_options'])) {
            return false;
        }

        $path = "config_options->{$optionId}";

        if (DB::connection()->getDriverName() === 'pgsql') {
            return $this->services()->where($path, (string) $valueId)->exists();
        }

        return $this->services()
            ->where(fn (Builder $query) => $query->where($path, $valueId)->orWhere($path, (string) $valueId))
            ->exists();
    }

    public function addonInUse(int $addonId): bool
    {
        return $this->hasColumns(self::SERVICE_ADDONS_TABLE, ['addon_id'])
            && DB::table(self::SERVICE_ADDONS_TABLE)->where('addon_id', $addonId)->exists();
    }

    private function services(): Builder
    {
        $query = DB::table(self::SERVICES_TABLE);

        return $this->hasColumns(self::SERVICES_TABLE, ['deleted_at']) ? $query->whereNull('deleted_at') : $query;
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasColumns(string $table, array $columns): bool
    {
        $key = $table.':'.implode(',', $columns);

        return $this->tables[$key] ??= Schema::hasTable($table) && Schema::hasColumns($table, $columns);
    }
}
