<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Exceptions\ImmutableInvoiceException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One allocated line of a numbered note. Never updated or deleted once written (vat-register §4.3, §9).
 *
 * @property int $id
 * @property int $tax_note_id
 * @property int|null $invoice_item_id null only for a late-fee debit note
 * @property string $description
 * @property int $quantity
 * @property int $unit_amount
 * @property int $amount net
 * @property bool $taxable
 * @property int $tax1_amount
 * @property int $tax2_amount
 * @property int $sort_order
 * @property-read TaxNote $note
 */
#[Fillable(['tax_note_id', 'invoice_item_id', 'description', 'quantity', 'unit_amount', 'amount', 'taxable', 'tax1_amount', 'tax2_amount', 'sort_order'])]
class TaxNoteLine extends Model
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

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableInvoiceException::numbered());
        static::deleting(fn () => throw ImmutableInvoiceException::numbered());
    }

    /**
     * @return BelongsTo<TaxNote, $this>
     */
    public function note(): BelongsTo
    {
        return $this->belongsTo(TaxNote::class, 'tax_note_id');
    }
}
