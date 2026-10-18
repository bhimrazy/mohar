<?php

namespace App\Modules\Orders\Models;

use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $promotion_id
 * @property int $client_id
 * @property int $order_id
 * @property string|null $item_type
 * @property int|null $item_id
 * @property int $discount_amount
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $released_at
 */
#[Fillable(['promotion_id', 'client_id', 'order_id', 'item_type', 'item_id', 'discount_amount', 'created_at', 'released_at'])]
class PromotionRedemption extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'discount_amount' => 'integer',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
