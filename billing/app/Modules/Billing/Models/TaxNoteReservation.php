<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A gateway refund that changes the supply, reserved before the provider is called and turned into a note only when the
 * provider has paid (ADR 0024 §5). The tables exist from PR 1a; the state machine is PR 4.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $transaction_id the pending refund row, whose id is the provider reference
 * @property string $reason_code
 * @property string|null $reason_text
 * @property string $digest
 * @property int|null $created_by
 * @property ReservationStatus $status
 * @property int|null $tax_note_id set when finalised
 * @property CarbonImmutable|null $created_at
 * @property-read Invoice $invoice
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TaxNoteReservationLine> $lines
 */
#[Fillable(['invoice_id', 'transaction_id', 'reason_code', 'reason_text', 'digest', 'created_by', 'status', 'tax_note_id'])]
class TaxNoteReservation extends Model
{
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['status' => ReservationStatus::class];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return HasMany<TaxNoteReservationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TaxNoteReservationLine::class, 'reservation_id')->orderBy('sort_order')->orderBy('id');
    }
}
