<?php

namespace App\Support\Currencies;

use App\Contracts\Currencies\CurrencyDirectory;
use App\Data\Currencies\CurrencyDisplay;
use App\Support\Money;

/**
 * The one formatter for amounts people read: dashboards, list meta, search results, merge fields, mail and
 * PDFs. It applies the currency's configured display (Admin → Currencies), e.g. "$1,234.50",
 * "₹1,23,456.00", "Rs 1,23,456.00", and "−Rs 1,200.00" for negatives (U+2212, like the UI ledger).
 * resources/js/lib/money.ts implements the same rules for the browser.
 *
 *   app(MoneyFormatter::class)->format(Money::of(2499, 'USD'));   // "$24.99"
 *
 * Money::format() ("24.99 USD") stays for logs, audit entries and CLI output, where the code matters.
 */
class MoneyFormatter
{
    /** Number formats: thousands and decimal separators; "1,23,456.78" is Indian lakh/crore grouping. */
    public const FORMATS = ['1234.56', '1,234.56', '1.234,56', '1 234,56', '1,23,456.78'];

    public const MINUS = "\u{2212}";

    /** Narrow symbols for currencies that are not configured (matches Intl "narrowSymbol" in en-US). */
    private const FALLBACK_SYMBOLS = [
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'INR' => '₹', 'NPR' => 'Rs ', 'JPY' => '¥', 'CNY' => '¥',
        'AUD' => '$', 'CAD' => '$', 'NZD' => '$', 'SGD' => '$', 'HKD' => '$', 'KRW' => '₩', 'PKR' => 'Rs ',
        'LKR' => 'Rs ', 'BDT' => '৳', 'PHP' => '₱', 'THB' => '฿', 'TRY' => '₺', 'ILS' => '₪', 'NGN' => '₦',
        'UAH' => '₴', 'VND' => '₫', 'BRL' => 'R$', 'ZAR' => 'R', 'CHF' => 'CHF ', 'AED' => 'AED ',
    ];

    public function __construct(private CurrencyDirectory $currencies) {}

    /**
     * @param  bool  $signed  prefix positive amounts with "+" (credit ledgers, adjustments)
     */
    public function format(Money $money, bool $signed = false): string
    {
        return self::render($money->amount, $this->display($money->currency), $signed);
    }

    public function amount(int $amount, string $currency, bool $signed = false): string
    {
        return self::render($amount, $this->display($currency), $signed);
    }

    public function display(string $currency): CurrencyDisplay
    {
        $code = strtoupper($currency);

        return $this->currencies->display($code) ?? self::fallback($code);
    }

    /**
     * Pure formatting of minor units (1/100) with a display definition.
     */
    public static function render(int $amount, CurrencyDisplay $display, bool $signed = false): string
    {
        $sign = match (true) {
            $amount < 0 => self::MINUS,
            $signed && $amount > 0 => '+',
            default => '',
        };

        return $sign.$display->prefix.self::number(abs($amount), $display->decimals, $display->format).$display->suffix;
    }

    /**
     * Group a non-negative amount of minor units (always 1/100, ADR 0002) into the display format.
     */
    public static function number(int $minor, int $decimals, string $format): string
    {
        [$thousands, $point] = match ($format) {
            '1234.56' => ['', '.'],
            '1.234,56' => ['.', ','],
            '1 234,56' => [' ', ','],
            default => [',', '.'],
        };

        $whole = (string) intdiv($minor, 100);
        $grouped = match (true) {
            $thousands === '' => $whole,
            $format === '1,23,456.78' => self::indianGrouping($whole),
            default => ltrim(strrev(implode($thousands, str_split(strrev($whole), 3))), $thousands),
        };

        if ($decimals <= 0) {
            return $grouped;
        }

        return $grouped.$point.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * "1234567" → "12,34,567" (last three digits, then pairs).
     */
    public static function indianGrouping(string $whole): string
    {
        if (strlen($whole) <= 3) {
            return $whole;
        }

        $rest = substr($whole, 0, -3);

        return strrev(implode(',', str_split(strrev($rest), 2))).','.substr($whole, -3);
    }

    public static function fallback(string $code): CurrencyDisplay
    {
        $code = strtoupper($code);
        $lakh = in_array($code, ['INR', 'NPR', 'PKR', 'BDT', 'LKR'], true);

        return new CurrencyDisplay($code, self::FALLBACK_SYMBOLS[$code] ?? $code.' ', format: $lakh ? '1,23,456.78' : '1,234.56');
    }
}
