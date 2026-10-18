<?php

namespace App\Modules\Core\Install;

use App\Contracts\Install\InstallsReferenceData;
use App\Data\Install\ReferenceDataResult;
use App\Modules\Core\Models\Currency;

/**
 * The primary currencies: USD (the default), INR and NPR. They are created only on the first install, while the
 * currencies table is empty. After that staff own the list: a later run never re-creates a currency staff deleted
 * and never changes a rate, symbol or format. Rates are starting values relative to USD; staff set real rates in
 * Admin → Setup → Currencies.
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
        $existing = Currency::query()->count();

        if ($existing > 0) {
            return new ReferenceDataResult(unchanged: $existing);
        }

        foreach (self::CURRENCIES as $code => $attributes) {
            Currency::query()->create(['code' => $code, ...$attributes, 'is_default' => $code === self::DEFAULT_CODE]);
        }

        return new ReferenceDataResult(created: count(self::CURRENCIES));
    }
}
