<?php

namespace App\Modules\PaymentsStripe\Support;

use App\Support\MinorUnits;
use App\Support\Money;

/**
 * Exact conversion between our minor units (per the currency's configured decimals) and Stripe's "smallest
 * currency unit" (docs/product/payments-stripe.md §5). Integer arithmetic only; an amount that can't be expressed
 * exactly is refused, never rounded.
 */
final class StripeAmount
{
    /**
     * Card presentment currencies Stripe lists (docs.stripe.com/currencies, 2026-10-18). UGX is left out until its
     * conflicting rules are confirmed in the sandbox (spec §5).
     */
    public const CURRENCIES = [
        'USD', 'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BIF', 'BMD',
        'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CVE', 'CZK',
        'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD',
        'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KRW', 'KYD',
        'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MUR', 'MVR', 'MWK',
        'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG',
        'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'STD', 'SZL',
        'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UYU', 'UZS', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XCG',
        'XOF', 'XPF', 'YER', 'ZAR', 'ZMW',
    ];

    /** Stripe amounts equal whole units for these. */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Sent as two-decimal values whose decimals must be 00 (Stripe's "special cases"). */
    private const WHOLE_UNITS_ONLY = ['ISK'];

    public static function supports(string $currency): bool
    {
        return in_array(strtoupper($currency), self::CURRENCIES, true);
    }

    /**
     * Our minor units → Stripe units, or null when the currency isn't supported or the amount can't be sent exactly.
     */
    public static function toStripe(Money $money): ?int
    {
        $currency = $money->currency;

        if (! self::supports($currency) || $money->amount < 0) {
            return null;
        }

        $amount = MinorUnits::rescale($money->amount, MinorUnits::decimals($currency), self::exponent($currency));

        if ($amount !== null && in_array($currency, self::WHOLE_UNITS_ONLY, true) && $amount % 100 !== 0) {
            return null;
        }

        return $amount;
    }

    /**
     * Stripe units (as reported by the API) → our minor units, or null when it isn't a non-negative integer or
     * doesn't fit our decimals exactly.
     */
    public static function fromStripe(mixed $amount, string $currency): ?int
    {
        $currency = strtoupper($currency);

        if (! is_int($amount) || $amount < 0 || ! self::supports($currency)) {
            return null;
        }

        return MinorUnits::rescale($amount, self::exponent($currency), MinorUnits::decimals($currency));
    }

    /** Decimals Stripe uses for the currency in API amounts. */
    public static function exponent(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }
}
