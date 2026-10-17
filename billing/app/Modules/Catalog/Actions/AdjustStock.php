<?php

namespace App\Modules\Catalog\Actions;

use App\Events\Catalog\ProductStockDepleted;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Relative stock change by staff (+ received, − written off). Applied atomically on the current value,
 * so it never overwrites units reserved by checkouts in the meantime.
 */
class AdjustStock
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @throws ValidationException when removing more units than are in stock
     */
    public function handle(Product $product, int $adjustment, ?string $reason = null): int
    {
        return DB::transaction(function () use ($product, $adjustment, $reason): int {
            $query = Product::query()->whereKey($product->id)->where('stock_enabled', true);

            $updated = $adjustment > 0
                ? $query->increment('stock_quantity', $adjustment)
                : $query->where('stock_quantity', '>=', -$adjustment)->decrement('stock_quantity', -$adjustment);

            if ($updated !== 1) {
                $current = (int) Product::query()->whereKey($product->id)->value('stock_quantity');

                throw ValidationException::withMessages(['adjustment' => __('Only :count in stock, so you can remove at most :count.', ['count' => $current])]);
            }

            $quantity = (int) Product::query()->whereKey($product->id)->value('stock_quantity');
            $this->activity->log('Product stock adjusted', $product, null, ['adjustment' => $adjustment, 'quantity' => $quantity, 'reason' => $reason]);

            if ($quantity === 0) {
                DB::afterCommit(fn () => ProductStockDepleted::dispatch($product->id));
            }

            return $quantity;
        });
    }
}
