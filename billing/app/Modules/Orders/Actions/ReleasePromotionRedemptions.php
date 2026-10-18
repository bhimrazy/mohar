<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Promotion;

/**
 * Give a promotion use back when its order is cancelled/flagged before payment: redemptions are marked
 * released and the promotion's `uses` is decremented once per order.
 */
class ReleasePromotionRedemptions
{
    public function handle(Order $order): void
    {
        $released = $order->redemptions()->whereNull('released_at')->update(['released_at' => now()]);

        if ($released > 0 && $order->promotion_id !== null) {
            Promotion::query()->whereKey($order->promotion_id)->where('uses', '>', 0)->decrement('uses');
        }
    }
}
