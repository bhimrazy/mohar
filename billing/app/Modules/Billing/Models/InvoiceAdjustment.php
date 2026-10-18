<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credit/debit note against an issued invoice. Amount is always positive; kind gives the direction.
 *
 * @property int $id
 * @property int $invoice_id
 * @property AdjustmentKind $kind
 * @property string|null $number
 * @property AdjustmentReason $reason
 * @property string|null $description
 * @property int $amount
 * @property int $taxable_amount
 * @property int $tax_amount
 * @property int|null $transaction_id
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read Invoice $invoice
 * @property-read User|null $creator
 */
#[Fillable(['invoice_id', 'kind', 'number', 'reason', 'description', 'amount', 'taxable_amount', 'tax_amount', 'transaction_id', 'created_by'])]
class InvoiceAdjustment extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => AdjustmentKind::class,
            'reason' => AdjustmentReason::class,
            'amount' => 'integer',
            'taxable_amount' => 'integer',
            'tax_amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Signed effect on the invoice balance.
     */
    public function signedAmount(): int
    {
        return $this->kind->sign() * $this->amount;
    }
}
