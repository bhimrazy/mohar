<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Models\Cart;

/**
 * Deletes carts past their expiry (idempotent).
 */
class PruneExpiredCarts
{
    public function handle(): int
    {
        return Cart::query()->where('expires_at', '<=', now())->delete();
    }
}
