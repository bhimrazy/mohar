<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Core\Support\ChunkedDelete;
use App\Modules\Orders\Models\Cart;

/**
 * Deletes carts past their expiry (idempotent).
 */
class PruneExpiredCarts
{
    public function handle(): int
    {
        return ChunkedDelete::run(Cart::query()->where('expires_at', '<=', now()));
    }
}
