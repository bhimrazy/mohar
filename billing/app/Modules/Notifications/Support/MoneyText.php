<?php

namespace App\Modules\Notifications\Support;

use App\Modules\Core\Models\Currency;

/**
 * Email money formatting per the product standard: currency symbol, Indian lakh grouping for INR/NPR
 * ($1,234.50 · ₹1,23,456.00 · Rs 1,23,456.00). Other currencies use their configured prefix, else the code.
 *
 * @deprecated Temporary module-local copy. The Currencies workstream (feature/currencies) adds one kernel
 *             formatter for all server-rendered amounts; whichever PR merges second switches Notifications
 *             (and Billing's merge fields) to it and deletes this class.
 */
final class MoneyText
{
    private const SYMBOLS = ['USD' => '$', 'INR' => '₹', 'NPR' => 'Rs ', 'EUR' => '€', 'GBP' => '£'];

    /** @var array<string, string> */
    private static array $prefixes = [];

    public static function format(int $amount, string $currency): string
    {
        $currency = strtoupper($currency);
        $absolute = abs($amount);
        $whole = intdiv($absolute, 100);
        $grouped = in_array($currency, ['INR', 'NPR'], true) ? self::lakh($whole) : number_format($whole);

        return ($amount < 0 ? '-' : '').self::symbol($currency).$grouped.sprintf('.%02d', $absolute % 100);
    }

    private static function lakh(int $whole): string
    {
        $digits = (string) $whole;

        if (strlen($digits) <= 3) {
            return $digits;
        }

        return strrev(implode(',', str_split(strrev(substr($digits, 0, -3)), 2))).','.substr($digits, -3);
    }

    private static function symbol(string $currency): string
    {
        if (isset(self::SYMBOLS[$currency])) {
            return self::SYMBOLS[$currency];
        }

        return self::$prefixes[$currency] ??= (($prefix = (string) Currency::query()->where('code', $currency)->value('prefix')) !== '' ? $prefix : $currency.' ');
    }
}
