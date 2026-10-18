<?php

namespace App\Modules\Orders\Models;

use App\Enums\BillingCycle;
use App\Modules\Orders\Database\Factories\OrderItemFactory;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderItemType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property OrderItemType $item_type
 * @property int|null $item_id
 * @property int|null $product_id
 * @property int|null $addon_id
 * @property int|null $parent_id
 * @property OrderItemStatus $status
 * @property string $description
 * @property string|null $domain
 * @property BillingCycle|null $cycle
 * @property int $years
 * @property int $quantity
 * @property int $first_amount
 * @property int $recurring_amount
 * @property int $setup_fee
 * @property int $discount_amount
 * @property bool $taxable
 * @property int|null $promotion_cycles_remaining
 * @property int $renewal_discount
 * @property string|null $auto_setup
 * @property bool $stock_reserved
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Order $order
 */
#[UseFactory(OrderItemFactory::class)]
#[Fillable([
    'order_id', 'item_type', 'item_id', 'product_id', 'addon_id', 'parent_id', 'status', 'description', 'domain', 'cycle',
    'years', 'quantity', 'first_amount', 'recurring_amount', 'setup_fee', 'discount_amount', 'taxable',
    'promotion_cycles_remaining', 'renewal_discount', 'auto_setup', 'stock_reserved',
])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'item_type' => OrderItemType::class,
            'status' => OrderItemStatus::class,
            'cycle' => BillingCycle::class,
            'first_amount' => 'integer',
            'recurring_amount' => 'integer',
            'setup_fee' => 'integer',
            'discount_amount' => 'integer',
            'years' => 'integer',
            'taxable' => 'boolean',
            'renewal_discount' => 'integer',
            'stock_reserved' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
