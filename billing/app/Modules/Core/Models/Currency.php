<?php

namespace App\Modules\Core\Models;

use App\Data\Currencies\CurrencyDisplay;
use App\Enums\PriceRounding;
use App\Modules\Core\Database\Factories\CurrencyFactory;
use App\Modules\Core\Support\CachedCurrencyDirectory;
use App\Support\Currencies\Decimal;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $prefix
 * @property string $suffix
 * @property int $decimals
 * @property string $format
 * @property string $exchange_rate
 * @property string $rate_source "manual" or an ExchangeRateSources key
 * @property bool $is_auto_rate
 * @property CarbonImmutable|null $rate_updated_at when the rate last changed
 * @property CarbonImmutable|null $rate_checked_at last successful fetch from the rate source
 * @property PriceRounding $price_rounding
 * @property string $price_buffer_percent
 * @property bool $is_default
 * @property CarbonImmutable|null $created_at
 */
#[UseFactory(CurrencyFactory::class)]
#[Fillable(['code', 'name', 'prefix', 'suffix', 'decimals', 'format', 'exchange_rate', 'rate_source', 'is_auto_rate', 'rate_updated_at', 'rate_checked_at', 'price_rounding', 'price_buffer_percent', 'is_default'])]
class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory;

    /** Supported number formats (thousands/decimal separators; "1,23,456.78" = Indian lakh grouping). */
    public const FORMATS = MoneyFormatter::FORMATS;

    /**
     * Supported minor-unit precisions. v1 stores every amount in 1/100 units (ADR 0002), so only 2-decimal
     * currencies are allowed; the column and convertTo() already handle 0/3 for a later version.
     */
    public const DECIMALS = [2];

    /** Exchange rates are stored and computed with twelve decimals (decimal(24,12)). */
    public const RATE_SCALE = 12;

    protected $attributes = [
        'decimals' => 2,
        'format' => '1,234.56',
        'rate_source' => 'manual',
        'price_rounding' => 'none',
        'price_buffer_percent' => 0,
    ];

    protected static function booted(): void
    {
        // Clients, invoices, quotes, transactions, prices and rate history store the code, so renaming it would orphan
        // them. Add a new currency instead.
        static::updating(function (self $currency): void {
            if ($currency->isDirty('code')) {
                throw new LogicException("Currency code {$currency->getOriginal('code')} cannot be changed.");
            }
        });

        // Flush now and again after commit, so a request that cached pre-commit values is corrected.
        $flush = function (): void {
            CachedCurrencyDirectory::flush();
            DB::afterCommit(fn () => CachedCurrencyDirectory::flush());
        };
        static::saved($flush);
        static::deleted($flush);
    }

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:12',
            'decimals' => 'integer',
            'is_auto_rate' => 'boolean',
            'rate_updated_at' => 'immutable_datetime',
            'rate_checked_at' => 'immutable_datetime',
            'price_rounding' => PriceRounding::class,
            'price_buffer_percent' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    public function display(): CurrencyDisplay
    {
        return new CurrencyDisplay($this->code, (string) $this->prefix, (string) $this->suffix, $this->decimals, $this->format);
    }

    public static function default(): self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'prefix' => '$', 'is_default' => true]);
    }

    /**
     * Format an amount with this currency's display settings (see App\Support\Currencies\MoneyFormatter).
     */
    public function format(Money $money): string
    {
        return MoneyFormatter::render($money->amount, $this->display());
    }

    /**
     * A converted price in this currency for a default-currency amount: amount × rate × (1 + buffer), rounded
     * half up to the minor unit once, then the rounding rule. Used by every module's price conversion so they
     * agree to the minor unit. Pass the default currency when converting many prices (avoids a query each).
     */
    public function priceFromDefault(int $amount, ?self $default = null): int
    {
        $default ??= self::default();
        $buffer = (string) $this->price_buffer_percent;

        if ($amount === 0 || ! Decimal::isPositive($buffer) || $this->code === $default->code) {
            return $this->price_rounding->apply($this->code === $default->code ? $amount : $this->fromDefault($amount, $default));
        }

        $converted = (int) Decimal::divide(
            Decimal::multiply(Decimal::multiply((string) $amount, (string) $this->exchange_rate), Decimal::add('100', $buffer)),
            Decimal::multiply((string) $default->exchange_rate, '100'),
            0,
        );

        return $this->price_rounding->apply($converted);
    }

    /**
     * Convert an amount in the default currency into this currency.
     */
    public function fromDefault(int $amount, ?self $default = null): int
    {
        return ($default ?? self::default())->convertTo($amount, $this);
    }

    /**
     * Convert minor units of this currency into minor units of the target currency:
     * round_half_up(amount × rate_target / rate_source × 10^(decimals_target − decimals_source)).
     */
    public function convertTo(int $amount, self $target): int
    {
        $shift = $target->decimals - $this->decimals;

        // Exact decimal maths (no overflow, no floats), rounded half up once.
        return (int) Decimal::divide(
            Decimal::multiply(Decimal::multiply((string) $amount, (string) $target->exchange_rate), '1'.str_repeat('0', max(0, $shift))),
            Decimal::multiply((string) $this->exchange_rate, '1'.str_repeat('0', max(0, -$shift))),
            0,
        );
    }
}
