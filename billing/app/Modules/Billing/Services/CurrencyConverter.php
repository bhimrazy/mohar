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
    /**
     * The cross rate as a decimal string with twelve decimals (Currency::RATE_SCALE), read from the stored rates and
     * never from the remote rate source. Fiscal documents store this string (ADR 0024 §2); the float of rate() would
     * lose digits.
     *
     * @throws BillingException when a currency has no positive rate
     */
    public function rateDecimal(string $from, string $to): string
    {
        if (strtoupper($from) === strtoupper($to)) {
            return Decimal::divide('1', '1', Currency::RATE_SCALE);
        }

        $rates = Currency::query()->whereIn('code', [strtoupper($from), strtoupper($to)])->pluck('exchange_rate', 'code');
        $source = (string) ($rates[strtoupper($from)] ?? '0');
        $target = (string) ($rates[strtoupper($to)] ?? '0');

        if (! Decimal::isPositive($source) || ! Decimal::isPositive($target)) {
            throw new BillingException(__('No exchange rate is configured for :from → :to.', ['from' => $from, 'to' => $to]));
        }

        // Cross rate via the default currency, exact to twelve decimals (stored on the transaction as is).
        return Decimal::divide($target, $source, Currency::RATE_SCALE);
    }

    public function rate(string $from, string $to): float
    {
        return (float) $this->rateDecimal($from, $to);
    }

    public function convert(int $amount, float $rate): int
    {
        return (int) round($amount * $rate, 0, PHP_ROUND_HALF_UP);
    }
}
