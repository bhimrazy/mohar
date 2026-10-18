<?php

namespace App\Modules\Services\Models;

use App\Modules\Services\Enums\ChangeKind;
use App\Modules\Services\Enums\ChangeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An upgrade/downgrade of a service.
 *
 * @property int $id
 * @property int $service_id
 * @property ChangeKind $kind
 * @property array{product_id: int, product_name: ?string, cycle: string, recurring_amount: int, next_due_date: ?string, billing_anchor_day: ?int} $from_snapshot
 * @property array{product_id: int, product_name: ?string, cycle: string, recurring_amount: int, next_due_date: ?string, billing_anchor_day: ?int} $to_snapshot
 * @property string $currency
 * @property int $credit_amount
 * @property int $charge_amount
 * @property int $amount_due
 * @property int|null $promotion_id
 * @property int|null $invoice_id
 * @property int|null $order_id
 * @property ChangeStatus $status
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable|null $created_at
 * @property-read Service $service
 */
#[Fillable([
    'service_id', 'kind', 'from_snapshot', 'to_snapshot', 'currency', 'credit_amount', 'charge_amount', 'amount_due',
    'promotion_id', 'invoice_id', 'order_id', 'status', 'expires_at', 'applied_at',
])]
class ServiceChange extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => ChangeKind::class,
            'status' => ChangeStatus::class,
            'from_snapshot' => 'array',
            'to_snapshot' => 'array',
            'credit_amount' => 'integer',
            'charge_amount' => 'integer',
            'amount_due' => 'integer',
            'expires_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }
}
