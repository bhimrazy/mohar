<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $quote_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_amount
 * @property string $discount_percent
 * @property int $amount
 * @property bool $taxable
 * @property int|null $product_id
 * @property int $sort_order
 * @property-read Quote $quote
 */
#[Fillable(['quote_id', 'description', 'quantity', 'unit_amount', 'discount_percent', 'amount', 'taxable', 'product_id', 'sort_order'])]
class QuoteItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount' => 'integer',
            'discount_percent' => 'decimal:2',
            'amount' => 'integer',
            'taxable' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
