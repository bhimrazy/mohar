<?php

namespace App\Modules\Catalog\Actions;

use App\Enums\BillingCycle;
use App\Events\Catalog\ProductPriceChanged;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\ConfigOptionValue;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Replace the price matrix (currency × cycle) of a product, addon or option value.
 * Price changes never touch existing services: they keep their stored recurring amount.
 */
class SyncPrices
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * `manual` marks a non-default-currency row as set by staff, so price conversion leaves it alone;
     * when a row omits it, the stored flag is kept.
     *
     * @param  list<array{currency: string, cycle: string, enabled?: bool, amount: int, setup_fee?: int, manual?: bool}>  $rows
     * @return int number of rows whose amount, setup fee or availability changed
     */
    public function handle(Product|Addon|ConfigOptionValue $priceable, array $rows): int
    {
        return DB::transaction(function () use ($priceable, $rows): int {
            $existing = $priceable->prices()->get()->keyBy(fn (Price $price): string => $price->currency.'|'.$price->cycle->value);
            $keep = [];
            $changes = [];

            foreach ($rows as $row) {
                $currency = strtoupper($row['currency']);
                $key = $currency.'|'.$row['cycle'];
                $keep[] = $key;
                /** @var Price|null $price */
                $price = $existing->get($key);

                $attributes = [
                    'enabled' => (bool) ($row['enabled'] ?? true),
                    'amount' => (int) $row['amount'],
                    'setup_fee' => (int) ($row['setup_fee'] ?? 0),
                    'is_manual' => array_key_exists('manual', $row) ? (bool) $row['manual'] : ($price !== null && $price->is_manual),
                ];

                if ($price === null) {
                    $priceable->prices()->create(['currency' => $currency, 'cycle' => BillingCycle::from($row['cycle']), ...$attributes]);
                    $changes[] = ['currency' => $currency, 'cycle' => $row['cycle'], 'old' => null, 'new' => $attributes['amount']];

                    continue;
                }

                $price->fill($attributes);

                if ($price->isDirty('enabled', 'amount', 'setup_fee')) {
                    $changes[] = ['currency' => $currency, 'cycle' => $row['cycle'], 'old' => $price->getOriginal('amount'), 'new' => $attributes['amount']];
                }

                $price->save();
            }

            $removed = $existing->toBase()->except($keep);

            foreach ($removed as $key => $price) {
                $changes[] = ['currency' => $price->currency, 'cycle' => $price->cycle->value, 'old' => $price->amount, 'new' => null];
            }

            Price::query()->whereKey($removed->map(fn (Price $price): int => $price->id)->values()->all())->delete();

            if ($changes !== []) {
                $this->activity->log('Prices updated', $priceable, null, ['changes' => $changes]);

                $type = $priceable->getMorphClass();
                $id = (int) $priceable->getKey();
                DB::afterCommit(function () use ($changes, $type, $id): void {
                    foreach ($changes as $change) {
                        ProductPriceChanged::dispatch($type, $id, $change['currency'], $change['cycle'], $change['old'], $change['new']);
                    }
                });
            }

            return count($changes);
        });
    }
}
