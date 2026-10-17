<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Orders\Database\Factories\OrderFactory;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An immutable commercial snapshot of a checkout. Later catalog/price changes never touch it.
 *
 * @property int $id
 * @property string $number
 * @property int $client_id
 * @property OrderKind $kind
 * @property OrderStatus $status
 * @property string $currency
 * @property int $subtotal
 * @property int $discount_total
 * @property int $setup_total
 * @property int $tax_total
 * @property int $total
 * @property int|null $promotion_id
 * @property string|null $promotion_code
 * @property string|null $payment_gateway
 * @property int|null $invoice_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $referral_code
 * @property int|null $placed_by_user_id
 * @property int|null $placed_by_staff_id
 * @property string|null $notes
 * @property string|null $admin_notes
 * @property string|null $fraud_provider
 * @property string|null $fraud_score
 * @property FraudDecision|null $fraud_decision
 * @property array<string, mixed>|null $fraud_payload
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $idempotency_key
 * @property string|null $idempotency_fingerprint
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(OrderFactory::class)]
#[Fillable([
    'number', 'client_id', 'kind', 'status', 'currency', 'subtotal', 'discount_total', 'setup_total', 'tax_total', 'total',
    'promotion_id', 'promotion_code', 'payment_gateway', 'invoice_id', 'ip_address', 'user_agent', 'referral_code',
    'placed_by_user_id', 'placed_by_staff_id', 'notes', 'admin_notes', 'fraud_provider', 'fraud_score', 'fraud_decision',
    'fraud_payload', 'accepted_at', 'cancelled_at', 'idempotency_key', 'idempotency_fingerprint',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => OrderKind::class,
            'status' => OrderStatus::class,
            'fraud_decision' => FraudDecision::class,
            'fraud_payload' => 'array',
            'fraud_score' => 'decimal:2',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'setup_total' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * @return HasMany<FraudCheck, $this>
     */
    public function fraudChecks(): HasMany
    {
        return $this->hasMany(FraudCheck::class);
    }

    /**
     * @return HasMany<PromotionRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function placedByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_staff_id');
    }

    /**
     * Atomically move the order from one of $from to $to (conditional UPDATE), so concurrent staff actions,
     * bulk jobs and listeners cannot both act on the same transition. Refreshes the model on success.
     *
     * @param  list<OrderStatus>  $from
     * @param  array<string, mixed>  $attributes  extra columns to set with the transition
     */
    public function transition(array $from, OrderStatus $to, array $attributes = []): bool
    {
        $claimed = self::query()
            ->whereKey($this->id)
            ->whereIn('status', array_map(fn (OrderStatus $status): string => $status->value, $from))
            ->update(['status' => $to->value, ...$attributes, 'updated_at' => now()]) === 1;

        if ($claimed) {
            $this->refresh();
        }

        return $claimed;
    }

    public function isPending(): bool
    {
        return $this->status === OrderStatus::Pending;
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
            $query->whereLike('number', '%'.$term.'%')
                ->orWhereHas('client', fn (Builder $client) => $client->search($term));
        });
    }
}
