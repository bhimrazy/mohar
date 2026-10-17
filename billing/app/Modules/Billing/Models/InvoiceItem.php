<?php

namespace App\Modules\Billing\Models;

use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Modules\Billing\Database\Factories\InvoiceItemFactory;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\ImmutableInvoiceException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $invoice_id
 * @property BillableType $type
 * @property int|null $billable_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_amount
 * @property int $amount
 * @property bool $taxable
 * @property int $tax_amount
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property int $sort_order
 * @property string|null $sac_code
 * @property string|null $period_guard
 * @property CarbonImmutable|null $cancelled_at
 * @property-read Invoice $invoice
 */
#[UseFactory(InvoiceItemFactory::class)]
#[Fillable([
    'invoice_id', 'type', 'billable_id', 'description', 'quantity', 'unit_amount', 'amount', 'taxable', 'tax_amount',
    'period_start', 'period_end', 'sort_order', 'sac_code', 'period_guard', 'cancelled_at',
])]
class InvoiceItem extends Model
{
    /** @use HasFactory<InvoiceItemFactory> */
    use HasFactory;

    private const FROZEN = ['type', 'billable_id', 'description', 'quantity', 'unit_amount', 'amount', 'taxable',
        'tax_amount', 'period_start', 'period_end'];

    protected function casts(): array
    {
        return [
            'type' => BillableType::class,
            'taxable' => 'boolean',
            'period_start' => 'date',
            'period_end' => 'date',
            'cancelled_at' => 'datetime',
            'quantity' => 'integer',
            'unit_amount' => 'integer',
            'amount' => 'integer',
            'tax_amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (InvoiceItem $item): void {
            if ($item->isDirty(self::FROZEN) && Invoice::query()->whereKey($item->invoice_id)->toBase()->value('status') !== InvoiceStatus::Draft->value) {
                throw ImmutableInvoiceException::make();
            }
        });
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Guard key against billing the same recurring item twice for one period.
     */
    public static function guardKey(BillableType $type, ?int $billableId, ?CarbonImmutable $periodStart): ?string
    {
        if ($billableId === null || $periodStart === null) {
            return null;
        }

        return $type->value.':'.$billableId.':'.$periodStart->toDateString();
    }

    public function toLineData(): InvoiceLineData
    {
        return new InvoiceLineData(
            description: $this->description,
            unitAmount: $this->unit_amount,
            quantity: $this->quantity,
            taxable: $this->taxable,
            billableType: $this->type,
            billableId: $this->billable_id,
            periodStart: $this->period_start,
            periodEnd: $this->period_end,
        );
    }
}
