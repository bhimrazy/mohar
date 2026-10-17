<?php

namespace App\Modules\Catalog\Actions;

use App\Events\Catalog\AddonCreated;
use App\Events\Catalog\AddonUpdated;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

class SaveAddon
{
    public function __construct(private SyncPrices $prices, private ActivityLogger $activity) {}

    /**
     * @param  array<string, mixed>  $data  validated AddonRequest data (incl. prices and product_ids)
     */
    public function handle(array $data, ?Addon $addon = null): Addon
    {
        return DB::transaction(function () use ($data, $addon): Addon {
            $addon ??= new Addon;
            $creating = ! $addon->exists;
            /** @var list<array{currency: string, cycle: string, enabled?: bool, amount: int, setup_fee?: int}> $prices */
            $prices = $data['prices'] ?? [];
            /** @var list<int> $productIds */
            $productIds = $data['product_ids'] ?? [];
            unset($data['prices'], $data['product_ids']);

            $addon->fill($data)->save();
            $addon->products()->sync($productIds);
            $this->prices->handle($addon, $prices);

            $this->activity->log($creating ? 'Addon created' : 'Addon updated', $addon);
            DB::afterCommit(fn () => $creating ? AddonCreated::dispatch($addon->id) : AddonUpdated::dispatch($addon->id));

            return $addon;
        });
    }
}
