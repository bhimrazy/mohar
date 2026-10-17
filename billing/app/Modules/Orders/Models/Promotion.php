<?php

namespace App\Modules\Orders\Models;

use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Database\Factories\PromotionFactory;
use App\Modules\Orders\Enums\PromotionType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property PromotionType $type
 * @property string $value
 * @property bool $recurring
 * @property int $recur_count
 * @property array{product_ids?: list<int>, addon_ids?: list<int>, tlds?: list<string>} $applies_to
 * @property array{product_ids?: list<int>, cycles?: list<string>, tlds?: list<string>} $requires
 * @property bool $requires_existing_ok
 * @property list<string>|null $cycles
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $expires_on
 * @property int $max_uses
 * @property int $uses
 * @property bool $apply_once
 * @property bool $once_per_client
 * @property bool $new_clients_only
 * @property bool $existing_clients_only
 * @property bool $lifetime
 * @property bool $allow_upgrades
 * @property string $upgrade_scope
 * @property int|null $min_cart_amount
 * @property bool $discount_setup_fee
 * @property string|null $admin_notes
 * @property CarbonImmutable|null $expired_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(PromotionFactory::class)]
#[Fillable([
    'code', 'type', 'value', 'recurring', 'recur_count', 'applies_to', 'requires', 'requires_existing_ok', 'cycles',
    'starts_on', 'expires_on', 'max_uses', 'uses', 'apply_once', 'once_per_client', 'new_clients_only',
    'existing_clients_only', 'lifetime', 'allow_upgrades', 'upgrade_scope', 'min_cart_amount', 'discount_setup_fee',
    'admin_notes', 'expired_at',
])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    protected $attributes = [
        'applies_to' => '{}',
        'requires' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'value' => 'decimal:2',
            'recurring' => 'boolean',
            'recur_count' => 'integer',
            'applies_to' => 'array',
            'requires' => 'array',
            'requires_existing_ok' => 'boolean',
            'cycles' => 'array',
            'starts_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'max_uses' => 'integer',
            'uses' => 'integer',
            'apply_once' => 'boolean',
            'once_per_client' => 'boolean',
            'new_clients_only' => 'boolean',
            'existing_clients_only' => 'boolean',
            'lifetime' => 'boolean',
            'allow_upgrades' => 'boolean',
            'min_cart_amount' => 'integer',
            'discount_setup_fee' => 'boolean',
            'expired_at' => 'datetime',
        ];
    }

    /**
     * Codes are case-insensitive; they are stored upper-case.
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => self::normalizeCode($value));
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function findByCode(?string $code): ?self
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return self::query()->where('code', self::normalizeCode($code))->first();
    }

    /**
     * @return HasMany<PromotionRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    /**
     * Value in hundredths: 2500 = 25.00% (percent) or 25.00 in the default currency (amount types).
     */
    public function valueHundredths(): int
    {
        return Money::fromDecimal($this->value, 'XXX')->amount;
    }

    /**
     * Short human description, e.g. "25% off", "$10.00 off", "Free setup". Amounts are in the default currency.
     */
    public function summary(?string $currencyPrefix = null): string
    {
        $currencyPrefix ??= Currency::default()->prefix;
        $value = rtrim(rtrim(number_format((float) $this->value, 2, '.', ''), '0'), '.');

        $text = match ($this->type) {
            PromotionType::Percent => "{$value}% off",
            PromotionType::Fixed => "{$currencyPrefix}".number_format((float) $this->value, 2).' off',
            PromotionType::PriceOverride => "Price {$currencyPrefix}".number_format((float) $this->value, 2),
            PromotionType::FreeSetup => 'Free setup',
        };

        if ($this->recurring && $this->type !== PromotionType::FreeSetup) {
            $text .= $this->recur_count > 0 ? " for {$this->recur_count} renewals" : ' on renewals';
        }

        return $text;
    }

    public function isRedeemed(): bool
    {
        return $this->uses > 0 || $this->redemptions()->exists();
    }
}
