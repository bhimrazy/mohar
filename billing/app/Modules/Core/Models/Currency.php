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

    protected $attributes = [
        'decimals' => 2,
        'format' => '1,234.56',
        'rate_source' => 'manual',
        'price_rounding' => 'none',
        'price_buffer_percent' => 0,
    ];

    protected static function booted(): void
    {
        static::saved(fn () => CachedCurrencyDirectory::flush());
        static::deleted(fn () => CachedCurrencyDirectory::flush());
    }

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:6',
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
     * A converted price in this currency for a default-currency amount: exchange rate, then the FX buffer,
     * then the rounding rule. Used by every module's price conversion so they agree to the minor unit.
     */
    public function priceFromDefault(int $amount): int
    {
        $converted = $this->fromDefault($amount);
        $buffer = (string) $this->price_buffer_percent;

        if ($converted !== 0 && Decimal::isPositive($buffer)) {
            $converted = (int) Decimal::round(Decimal::multiply((string) $converted, Decimal::add('1', Decimal::divide($buffer, '100', 6))), 0);
        }

        return $this->price_rounding->apply($converted);
    }

    /**
     * Convert an amount in the default currency into this currency.
     */
    public function fromDefault(int $amount): int
    {
        return self::default()->convertTo($amount, $this);
    }

    /**
     * Convert minor units of this currency into minor units of the target currency:
     * round_half_up(amount × rate_target / rate_source × 10^(decimals_target − decimals_source)).
     */
    public function convertTo(int $amount, self $target): int
    {
        $sourceRate = self::rateToInteger($this->exchange_rate);
        $targetRate = self::rateToInteger($target->exchange_rate);
        $shift = $target->decimals - $this->decimals;

        $scaleUp = 10 ** max(0, $shift);
        $denominator = $sourceRate * (10 ** max(0, -$shift));
        $fitsInInteger = (float) abs($amount) * $targetRate * $scaleUp < PHP_INT_MAX;

        if ($fitsInInteger && $denominator > 0) {
            $numerator = abs($amount) * $targetRate * $scaleUp;
            $converted = intdiv($numerator, $denominator);
            $converted += ($numerator % $denominator) * 2 >= $denominator ? 1 : 0;
        } else {
            $converted = (int) round(abs($amount) * $targetRate / $sourceRate * (10 ** $shift), 0, PHP_ROUND_HALF_UP);
        }

        return $amount < 0 ? -$converted : $converted;
    }

    /**
     * "150.000000" → 150000000 (rates have six decimal places).
     */
    private static function rateToInteger(string $rate): int
    {
        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');

        return (int) $whole * 1_000_000 + (int) str_pad(substr($fraction, 0, 6), 6, '0');
    }
}
