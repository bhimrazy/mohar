<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Core\Models\Currency;

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
        $source = (float) ($rates[strtoupper($from)] ?? 0);
        $target = (float) ($rates[strtoupper($to)] ?? 0);

        if ($source <= 0 || $target <= 0) {
            throw new BillingException(__('No exchange rate is configured for :from → :to.', ['from' => $from, 'to' => $to]));
        }

        return round($target / $source, 6);
    }

    public function convert(int $amount, float $rate): int
    {
        return (int) round($amount * $rate, 0, PHP_ROUND_HALF_UP);
    }
}
