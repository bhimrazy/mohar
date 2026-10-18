<?php

namespace App\Modules\Core\Install;

use App\Contracts\Install\InstallsReferenceData;
use App\Data\Install\ReferenceDataResult;
use App\Modules\Core\Models\Currency;

/**
 * The primary currencies: USD (the default), INR and NPR. A missing one is created; an existing one is never changed,
 * because staff own its rate, symbol and format after install. USD becomes the default only when no currency is.
 * Rates are starting values relative to USD; staff set real rates in Admin → Settings → Currencies.
 */
final class DefaultCurrencies implements InstallsReferenceData
{
    public const KEY = 'core_currencies';

    public const DEFAULT_CODE = 'USD';

    /** @var array<string, array{name: string, prefix: string, suffix: string, decimals: int, format: string, exchange_rate: string}> */
    public const CURRENCIES = [
        'USD' => ['name' => 'US Dollar', 'prefix' => '$', 'suffix' => '', 'decimals' => 2, 'format' => '1,234.56', 'exchange_rate' => '1'],
        'INR' => ['name' => 'Indian Rupee', 'prefix' => '₹', 'suffix' => '', 'decimals' => 2, 'format' => '1,234.56', 'exchange_rate' => '84'],
        'NPR' => ['name' => 'Nepalese Rupee', 'prefix' => 'रू ', 'suffix' => '', 'decimals' => 2, 'format' => '1,234.56', 'exchange_rate' => '134.4'],
    ];

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
        $hasDefault = Currency::query()->where('is_default', true)->exists();
        $created = 0;

        foreach (self::CURRENCIES as $code => $attributes) {
            $currency = Currency::query()->firstOrCreate(['code' => $code], [
                ...$attributes,
                'is_default' => $code === self::DEFAULT_CODE && ! $hasDefault,
            ]);

            $created += $currency->wasRecentlyCreated ? 1 : 0;
        }

        return new ReferenceDataResult(created: $created, unchanged: count(self::CURRENCIES) - $created);
    }
}
