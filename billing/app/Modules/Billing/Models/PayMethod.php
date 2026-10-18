<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Database\Factories\PayMethodFactory;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tokenised payment method stored at the gateway. Never holds card numbers or CVV.
 *
 * @property int $id
 * @property int $client_id
 * @property string $gateway
 * @property string $kind
 * @property string $token
 * @property string|null $brand
 * @property string|null $last4
 * @property int|null $expiry_month
 * @property int|null $expiry_year
 * @property string|null $description
 * @property bool $is_default
 * @property int $failure_count
 * @property CarbonImmutable|null $disabled_at
 * @property CarbonImmutable|null $created_at
 * @property-read Client $client
 */
#[UseFactory(PayMethodFactory::class)]
#[Fillable(['client_id', 'gateway', 'kind', 'token', 'brand', 'last4', 'expiry_month', 'expiry_year', 'billing_contact_id', 'description', 'is_default'])]
#[Hidden(['token'])]
class PayMethod extends Model
{
    /** @use HasFactory<PayMethodFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'is_default' => 'boolean',
            'disabled_at' => 'datetime',
            'expiry_month' => 'integer',
            'expiry_year' => 'integer',
            'failure_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isExpired(?CarbonImmutable $today = null): bool
    {
        if ($this->expiry_year === null || $this->expiry_month === null) {
            return false;
        }

        $today ??= CarbonImmutable::today();

        return CarbonImmutable::create($this->expiry_year, $this->expiry_month, 1)->endOfMonth()->lt($today);
    }

    public function isUsable(?CarbonImmutable $today = null): bool
    {
        return $this->disabled_at === null && ! $this->isExpired($today);
    }

    public function label(): string
    {
        return $this->description ?? trim(ucfirst((string) $this->brand).' •••• '.$this->last4);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('disabled_at');
    }
}
