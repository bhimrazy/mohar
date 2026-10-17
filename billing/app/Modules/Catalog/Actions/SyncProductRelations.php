<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Replace a product's config option groups, applicable addons or upgrade targets.
 */
class SyncProductRelations
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @param  list<int>  $groupIds
     */
    public function configOptionGroups(Product $product, array $groupIds): void
    {
        DB::transaction(function () use ($product, $groupIds): void {
            $product->configOptionGroups()->sync($groupIds);
            $this->activity->log('Product configurable options updated', $product, null, ['groups' => $groupIds]);
        });
    }

    /**
     * @param  list<int>  $addonIds
     */
    public function addons(Product $product, array $addonIds): void
    {
        DB::transaction(function () use ($product, $addonIds): void {
            $product->addons()->sync($addonIds);
            $this->activity->log('Product addons updated', $product, null, ['addons' => $addonIds]);
        });
    }

    /**
     * @param  list<int>  $targetIds
     */
    public function upgradePaths(Product $product, array $targetIds, bool $allowConfigOptionUpgrades): void
    {
        DB::transaction(function () use ($product, $targetIds, $allowConfigOptionUpgrades): void {
            $product->upgradeTargets()->sync(array_values(array_diff($targetIds, [$product->id])));
            $product->update(['allow_config_option_upgrades' => $allowConfigOptionUpgrades]);
            $this->activity->log('Product upgrade paths updated', $product, null, ['targets' => $targetIds]);
        });
    }

    /**
     * @param  array{provisioning_driver: string, server_group_id: ?int, driver_config: array<string, string>}  $data
     */
    public function provisioning(Product $product, array $data): void
    {
        DB::transaction(function () use ($product, $data): void {
            $product->update($data);
            $this->activity->log('Product provisioning updated', $product, null, ['driver' => $data['provisioning_driver']]);
        });
    }
}
