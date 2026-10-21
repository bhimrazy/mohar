<?php

namespace App\Modules\Core\Install;

use App\Contracts\Install\InstallsReferenceData;
use App\Data\Install\ReferenceDataResult;
use App\Modules\Core\Models\Currency;
use App\Support\Currencies\Decimal;

/**
 * The primary currencies: USD, INR and NPR, with NPR the default on a fresh install (Nepal first; the install can
 * choose USD or INR instead). They are created only on the first install, while the
 * currencies table is empty. After that staff own the list: a later run never re-creates a currency staff deleted
 * and never changes a rate, symbol or format. Starting rates are the ECB (INR) and Nepal Rastra Bank (NPR) rates of
 * 2026-10-18 relative to USD, with a manual rate source; staff choose a source and set real rates in
 * Admin → Setup → Currencies. Rupee prices round to whole units (#43); USD is not rounded.
 */
final class DefaultCurrencies implements InstallsReferenceData
{
    public const KEY = 'core_currencies';

    /** The demo seed's default currency. */
    public const DEMO_CODE = 'USD';

    /** @var array<string, array{name: string, prefix: string, suffix: string, decimals: int, format: string, exchange_rate: string, rate_source: string, price_rounding: string, price_buffer_percent: string}> */
    public const CURRENCIES = [
        'USD' => ['name' => 'US Dollar', 'prefix' => '$', 'suffix' => '', 'decimals' => 2, 'format' => '1,234.56', 'exchange_rate' => '1', 'rate_source' => 'manual', 'price_rounding' => 'none', 'price_buffer_percent' => '0'],
        'INR' => ['name' => 'Indian Rupee', 'prefix' => '₹', 'suffix' => '', 'decimals' => 2, 'format' => '1,23,456.78', 'exchange_rate' => '94.964898', 'rate_source' => 'manual', 'price_rounding' => 'whole', 'price_buffer_percent' => '0'],
        'NPR' => ['name' => 'Nepalese Rupee', 'prefix' => 'Rs ', 'suffix' => '', 'decimals' => 2, 'format' => '1,23,456.78', 'exchange_rate' => '151.940000', 'rate_source' => 'manual', 'price_rounding' => 'whole', 'price_buffer_percent' => '0'],
    ];

    public function __construct(private RegionalDefaults $regional) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Currencies';
    }

    public function install(): ReferenceDataResult
    {
        $existing = Currency::query()->count();

        if ($existing > 0) {
            return new ReferenceDataResult(unchanged: $existing);
        }

        $default = array_key_exists($this->regional->currency(), self::CURRENCIES) ? $this->regional->currency() : RegionalDefaults::CURRENCY;

        foreach (self::CURRENCIES as $code => $attributes) {
            Currency::query()->create([
                'code' => $code,
                ...$attributes,
                'exchange_rate' => self::rateAgainst($default, $code),
                'is_default' => $code === $default,
            ]);
        }

        return new ReferenceDataResult(created: count(self::CURRENCIES));
    }

    /**
     * Units of $code per one unit of the default currency. The table lists rates per US dollar, so another default
     * rebases them: 1 NPR = (USD per NPR) × (code per USD).
     */
    private static function rateAgainst(string $default, string $code): string
    {
        if ($default === $code) {
            return '1';
        }

        $perDollar = fn (string $currency): string => self::CURRENCIES[$currency]['exchange_rate'];

        return Decimal::round(Decimal::divide($perDollar($code), $perDollar($default), Currency::RATE_SCALE + 2), Currency::RATE_SCALE);
    }
}
