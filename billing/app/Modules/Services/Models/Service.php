<?php

namespace App\Modules\Services\Models;

use App\Enums\BillingCycle;
use App\Modules\Core\Models\Client;
use App\Modules\Services\Database\Factories\ServiceFactory;
use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Enums\ServiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A client's instance of a product.
 *
 * @property int $id
 * @property int $client_id
 * @property int $product_id
 * @property string|null $product_name
 * @property int|null $order_id
 * @property ServiceStatus $status
 * @property BillingCycle $cycle
 * @property string $currency
 * @property int $quantity
 * @property string|null $domain
 * @property int|null $server_id
 * @property string|null $username
 * @property string|null $password
 * @property string|null $dedicated_ip
 * @property list<string>|null $assigned_ips
 * @property int $first_payment_amount
 * @property int $recurring_amount
 * @property int|null $promotion_id
 * @property int|null $promotion_cycles_remaining
 * @property int $renewal_discount
 * @property CarbonImmutable $registration_date
 * @property int|null $billing_anchor_day
 * @property CarbonImmutable|null $next_due_date
 * @property CarbonImmutable|null $next_invoice_date
 * @property int $renewals_invoiced
 * @property int $recurring_cycles_limit
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $suspended_at
 * @property string|null $suspension_reason
 * @property CarbonImmutable|null $suspend_exempt_until
 * @property int|null $suspend_days_override
 * @property int|null $terminate_days_override
 * @property bool $do_not_renew
 * @property bool $terminate_at_period_end
 * @property CarbonImmutable|null $termination_date
 * @property string|null $payment_gateway
 * @property array<int|string, mixed>|null $config_options
 * @property array<int|string, mixed>|null $custom_fields
 * @property array<string, mixed>|null $driver_data
 * @property string|null $admin_notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Client $client
 * @property-read Server|null $server
 */
#[UseFactory(ServiceFactory::class)]
#[Hidden(['password', 'custom_fields', 'driver_data'])]
#[Fillable([
    'client_id', 'product_id', 'product_name', 'order_id', 'status', 'cycle', 'currency', 'quantity', 'domain',
    'server_id', 'username', 'password', 'dedicated_ip', 'assigned_ips', 'first_payment_amount', 'recurring_amount',
    'promotion_id', 'promotion_cycles_remaining', 'renewal_discount', 'registration_date', 'billing_anchor_day', 'next_due_date',
    'next_invoice_date', 'renewals_invoiced', 'recurring_cycles_limit', 'activated_at', 'suspended_at',
    'suspension_reason', 'suspend_exempt_until', 'suspend_days_override', 'terminate_days_override', 'do_not_renew',
    'terminate_at_period_end', 'termination_date', 'payment_gateway', 'config_options', 'custom_fields',
    'driver_data', 'admin_notes',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => ServiceStatus::class,
            'cycle' => BillingCycle::class,
            'password' => 'encrypted',
            'custom_fields' => 'encrypted:array',
            'config_options' => 'array',
            'assigned_ips' => 'array',
            'driver_data' => 'array',
            'registration_date' => 'immutable_date',
            'next_due_date' => 'immutable_date',
            'next_invoice_date' => 'immutable_date',
            'suspend_exempt_until' => 'immutable_date',
            'termination_date' => 'immutable_date',
            'activated_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'do_not_renew' => 'boolean',
            'terminate_at_period_end' => 'boolean',
            'first_payment_amount' => 'integer',
            'recurring_amount' => 'integer',
            'renewal_discount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return HasMany<ServiceAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(ServiceAddon::class);
    }

    /**
     * @return HasMany<ServiceConfigOption, $this>
     */
    public function configOptionSelections(): HasMany
    {
        return $this->hasMany(ServiceConfigOption::class);
    }

    /**
     * Store config option selections (option id => value id or quantity) in the json snapshot and the
     * normalized table.
     *
     * @param  array<int|string, int|string>  $selections
     */
    public function syncConfigOptions(array $selections): void
    {
        $normalized = [];

        foreach ($selections as $optionId => $valueId) {
            $normalized[(int) $optionId] = (int) $valueId;
        }

        $this->update(['config_options' => [...($this->config_options ?? []), 'selections' => $normalized]]);
        $this->configOptionSelections()->delete();
        $this->configOptionSelections()->createMany(array_map(
            fn (int $optionId, int $valueId): array => ['option_id' => $optionId, 'value_id' => $valueId],
            array_keys($normalized),
            $normalized,
        ));
    }

    /**
     * @return HasMany<ServiceInvoice, $this>
     */
    public function invoiceLinks(): HasMany
    {
        return $this->hasMany(ServiceInvoice::class);
    }

    /**
     * @return HasMany<ServiceChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(ServiceChange::class);
    }

    /**
     * @return HasMany<CancellationRequest, $this>
     */
    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(CancellationRequest::class);
    }

    /**
     * @return HasOne<CancellationRequest, $this>
     */
    public function openCancellationRequest(): HasOne
    {
        return $this->hasOne(CancellationRequest::class)->where('status', CancellationStatus::Open->value)->latestOfMany();
    }

    /**
     * @return HasMany<ModuleCommand, $this>
     */
    public function moduleCommands(): HasMany
    {
        return $this->hasMany(ModuleCommand::class);
    }

    /**
     * @return HasOne<UsageSnapshot, $this>
     */
    public function latestUsage(): HasOne
    {
        return $this->hasOne(UsageSnapshot::class)->latestOfMany('captured_at');
    }

    /**
     * Human label: the domain, else the product name.
     */
    public function label(): string
    {
        return $this->domain ?: ($this->product_name ?? "Service #{$this->id}");
    }

    public function isExemptFromAutomation(CarbonImmutable $today): bool
    {
        return $this->suspend_exempt_until !== null && $this->suspend_exempt_until->gte($today);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $like = '%'.$term.'%';
            $query->whereLike('domain', $like)
                ->orWhereLike('username', $like)
                ->orWhereLike('product_name', $like)
                ->orWhereLike('dedicated_ip', $like);

            if (ctype_digit($term)) {
                $query->orWhere('id', (int) $term);
            }
        });
    }
}
