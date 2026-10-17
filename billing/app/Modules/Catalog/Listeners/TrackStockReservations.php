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
 * before its service was ever activated (catalog spec §3.5).
 *
 * Order-independent and idempotent per order (ADR 0008): a cancellation leaves a release marker, so
 * reservations recorded later (OrderPlaced after OrderMarkedFraud at checkout) are returned at once.
 */
class TrackStockReservations
{
    public const RELEASES_TABLE = 'product_stock_order_releases';

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

        DB::transaction(function () use ($event, $stocked): void {
            foreach ($event->items as $item) {
                if ($item['type'] === 'service' && in_array($item['product_id'], $stocked, true)) {
                    StockReservation::query()->firstOrCreate(
                        ['order_id' => $event->orderId, 'service_id' => $item['id']],
                        ['product_id' => $item['product_id']],
                    );
                }
            }

            if (DB::table(self::RELEASES_TABLE)->where('order_id', $event->orderId)->exists()) {
                $this->releaseReservations($event->orderId);
            }
        });
    }

    public function serviceActivated(ServiceActivated $event): void
    {
        StockReservation::query()
            ->where('service_id', $event->serviceId)
            ->whereNull('activated_at')
            ->whereNull('released_at')
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
            DB::table(self::RELEASES_TABLE)->insertOrIgnore(['order_id' => $orderId, 'created_at' => now(), 'updated_at' => now()]);
            $this->releaseReservations($orderId);
        });
    }

    /**
     * Return stock for the order's reservations whose service never activated (each reservation once).
     */
    private function releaseReservations(int $orderId): void
    {
        $reservations = StockReservation::query()
            ->where('order_id', $orderId)
            ->whereNull('activated_at')
            ->whereNull('released_at')
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        foreach ($reservations->groupBy('product_id') as $productId => $rows) {
            Product::withTrashed()->whereKey($productId)->where('stock_enabled', true)->increment('stock_quantity', $rows->count());
        }

        StockReservation::query()->whereKey($reservations->modelKeys())->update(['released_at' => now()]);
    }
}
