<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\CurrencyFactory;
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
 * @property bool $is_auto_rate
 * @property CarbonImmutable|null $rate_updated_at
 * @property bool $is_default
 */
#[UseFactory(CurrencyFactory::class)]
#[Fillable(['code', 'name', 'prefix', 'suffix', 'decimals', 'format', 'exchange_rate', 'is_auto_rate', 'rate_updated_at', 'is_default'])]
class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory;

    /** Supported number formats (thousands/decimal separators). */
    public const FORMATS = ['1234.56', '1,234.56', '1.234,56', '1 234,56'];

    /**
     * Supported minor-unit precisions. v1 stores every amount in 1/100 units (ADR 0002), so only 2-decimal
     * currencies are allowed; the column and convertTo() already handle 0/3 for a later version.
     */
    public const DECIMALS = [2];

    protected $attributes = [
        'decimals' => 2,
        'format' => '1,234.56',
    ];

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:6',
            'decimals' => 'integer',
            'is_auto_rate' => 'boolean',
            'rate_updated_at' => 'immutable_datetime',
            'is_default' => 'boolean',
        ];
    }

    public static function default(): self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'prefix' => '$', 'is_default' => true]);
    }

    public function format(Money $money): string
    {
        [$thousands, $decimal] = match ($this->format) {
            '1234.56' => ['', '.'],
            '1.234,56' => ['.', ','],
            '1 234,56' => [' ', ','],
            default => [',', '.'],
        };

        return $this->prefix.number_format($money->amount / (10 ** $this->decimals), $this->decimals, $decimal, $thousands).$this->suffix;
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
