<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a refund reservation. A pending reservation's lines count in the line caps and the invoice cap.
 *
 * @property int $id
 * @property int $reservation_id
 * @property int|null $invoice_item_id
 * @property int $quantity
 * @property int $unit_amount
 * @property int $amount
 * @property bool $taxable
 * @property int $tax1_amount
 * @property int $tax2_amount
 * @property int $sort_order
 */
#[Fillable(['reservation_id', 'invoice_item_id', 'quantity', 'unit_amount', 'amount', 'taxable', 'tax1_amount', 'tax2_amount', 'sort_order'])]
class TaxNoteReservationLine extends Model
{
    protected function casts(): array
    {
        return [
            'taxable' => 'boolean',
            'quantity' => 'integer',
            'unit_amount' => 'integer',
            'amount' => 'integer',
            'tax1_amount' => 'integer',
            'tax2_amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TaxNoteReservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(TaxNoteReservation::class, 'reservation_id');
    }
}
