<?php

namespace App\Modules\Catalog\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One unit of stock taken by an order item, so it can be returned if the order is cancelled before activation.
 *
 * @property int $id
 * @property int $product_id
 * @property int $order_id
 * @property int|null $service_id
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['product_id', 'order_id', 'service_id', 'activated_at', 'released_at'])]
class StockReservation extends Model
{
    protected $table = 'product_stock_reservations';

    protected function casts(): array
    {
        return [
            'activated_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
