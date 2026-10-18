<?php

namespace App\Modules\Services\Models;

use App\Enums\BillingCycle;
use App\Modules\Services\Database\Factories\ServiceAddonFactory;
use App\Modules\Services\Enums\ServiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An addon attached to a service, with its own billing dates and status.
 *
 * @property int $id
 * @property int $service_id
 * @property int|null $addon_id
 * @property string $name
 * @property int|null $order_id
 * @property ServiceStatus $status
 * @property BillingCycle $cycle
 * @property string $currency
 * @property int $setup_fee
 * @property int $recurring_amount
 * @property CarbonImmutable $registration_date
 * @property int|null $billing_anchor_day
 * @property CarbonImmutable|null $next_due_date
 * @property CarbonImmutable|null $next_invoice_date
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $suspended_at
 * @property string|null $suspension_reason
 * @property CarbonImmutable|null $suspend_exempt_until
 * @property bool $suspend_parent_on_overdue
 * @property CarbonImmutable|null $termination_date
 * @property array<string, mixed>|null $driver_data
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Service $service
 */
#[UseFactory(ServiceAddonFactory::class)]
#[Fillable([
    'service_id', 'addon_id', 'name', 'order_id', 'status', 'cycle', 'currency', 'setup_fee', 'recurring_amount',
    'registration_date', 'billing_anchor_day', 'next_due_date', 'next_invoice_date', 'activated_at', 'suspended_at',
    'suspension_reason', 'suspend_exempt_until', 'suspend_parent_on_overdue', 'termination_date', 'driver_data',
])]
class ServiceAddon extends Model
{
    /** @use HasFactory<ServiceAddonFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ServiceStatus::class,
            'cycle' => BillingCycle::class,
            'driver_data' => 'array',
            'registration_date' => 'immutable_date',
            'next_due_date' => 'immutable_date',
            'next_invoice_date' => 'immutable_date',
            'suspend_exempt_until' => 'immutable_date',
            'termination_date' => 'immutable_date',
            'activated_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'suspend_parent_on_overdue' => 'boolean',
            'setup_fee' => 'integer',
            'recurring_amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function isExemptFromAutomation(CarbonImmutable $today): bool
    {
        return $this->suspend_exempt_until !== null && $this->suspend_exempt_until->gte($today);
    }
}
