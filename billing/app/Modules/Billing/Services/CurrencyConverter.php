<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Core\Models\Currency;
use App\Support\Currencies\Decimal;

/**
 * Conversion between client currencies using the configured exchange rates (relative to the default currency).
 */
class CurrencyConverter
{
    public function rate(string $from, string $to): float
    {
        if (strtoupper($from) === strtoupper($to)) {
            return 1.0;
        }

        $rates = Currency::query()->whereIn('code', [strtoupper($from), strtoupper($to)])->pluck('exchange_rate', 'code');
        $source = (string) ($rates[strtoupper($from)] ?? '0');
        $target = (string) ($rates[strtoupper($to)] ?? '0');

        if (! Decimal::isPositive($source) || ! Decimal::isPositive($target)) {
            throw new BillingException(__('No exchange rate is configured for :from → :to.', ['from' => $from, 'to' => $to]));
        }

        // Cross rate via the default currency, exact to six decimals (stored on the transaction as is).
        return (float) Decimal::divide($target, $source, 6);
    }

    public function convert(int $amount, float $rate): int
    {
        return (int) round($amount * $rate, 0, PHP_ROUND_HALF_UP);
    }
}
