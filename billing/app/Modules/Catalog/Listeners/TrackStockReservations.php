<?php

namespace App\Modules\Catalog\Listeners;

use App\Events\Orders\OrderCancelled;
use App\Events\Orders\OrderMarkedFraud;
use App\Events\Orders\OrderPlaced;
use App\Events\Services\ServiceActivated;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\StockReservation;
use Illuminate\Support\Facades\DB;

/**
 * Remembers which order took stock so it can be returned when the order is cancelled or marked fraud
 * before its service was ever activated (catalog spec §3.5). Every handler is idempotent.
 */
class TrackStockReservations
{
    public function orderPlaced(OrderPlaced $event): void
    {
        $productIds = array_values(array_unique(array_filter(array_map(
            fn (array $item): ?int => $item['type'] === 'service' ? $item['product_id'] : null,
            $event->items,
        ))));

        if ($productIds === []) {
            return;
        }

        $stocked = Product::withTrashed()->whereKey($productIds)->where('stock_enabled', true)->pluck('id')->all();

        foreach ($event->items as $item) {
            if ($item['type'] === 'service' && in_array($item['product_id'], $stocked, true)) {
                StockReservation::query()->firstOrCreate(
                    ['order_id' => $event->orderId, 'service_id' => $item['id']],
                    ['product_id' => $item['product_id']],
                );
            }
        }
    }

    public function serviceActivated(ServiceActivated $event): void
    {
        StockReservation::query()
            ->where('service_id', $event->serviceId)
            ->whereNull('activated_at')
            ->update(['activated_at' => now()]);
    }

    public function orderCancelled(OrderCancelled $event): void
    {
        $this->release($event->orderId);
    }

    public function orderMarkedFraud(OrderMarkedFraud $event): void
    {
        $this->release($event->orderId);
    }

    private function release(int $orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            $reservations = StockReservation::query()
                ->where('order_id', $orderId)
                ->whereNull('activated_at')
                ->whereNull('released_at')
                ->lockForUpdate()
                ->get();

            foreach ($reservations->groupBy('product_id') as $productId => $rows) {
                Product::withTrashed()->whereKey($productId)->where('stock_enabled', true)->increment('stock_quantity', $rows->count());
            }

            StockReservation::query()->whereKey($reservations->modelKeys())->update(['released_at' => now()]);
        });
    }
}
