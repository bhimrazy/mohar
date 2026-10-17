<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\CurrencyFactory;
use App\Support\Money;
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
 * @property string $exchange_rate
 * @property bool $is_default
 */
#[UseFactory(CurrencyFactory::class)]
#[Fillable(['code', 'name', 'prefix', 'suffix', 'exchange_rate', 'is_default'])]
class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:6',
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
        return $this->prefix.number_format($money->amount / 100, 2).$this->suffix;
    }

    /**
     * Convert an amount in the default currency into this currency.
     */
    public function fromDefault(int $amount): int
    {
        return (int) round($amount * (float) $this->exchange_rate);
    }
}
