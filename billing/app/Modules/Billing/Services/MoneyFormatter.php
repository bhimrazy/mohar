<?php

namespace App\Modules\Billing\Services;

use App\Modules\Core\Models\Currency;

/**
 * Document money formatting: currency symbol plus locale grouping (INR/NPR use Indian lakh/crore grouping,
 * e.g. ₹1,23,456.78). Used for PDFs; the UI formats with lib/money.ts.
 */
class MoneyFormatter
{
    /** Symbols the PDF font (DejaVu Sans) can render; Devanagari "रू" can't, so NPR prints as "Rs.". */
    private const PDF_SYMBOLS = ['USD' => '$', 'INR' => '₹', 'NPR' => 'Rs. ', 'EUR' => '€', 'GBP' => '£'];

    /** @var array<string, string> */
    private array $prefixes = [];

    public function format(int $amount, string $currency): string
    {
        $currency = strtoupper($currency);
        $negative = $amount < 0;
        $absolute = abs($amount);
        $whole = intdiv($absolute, 100);
        $grouped = in_array($currency, ['INR', 'NPR'], true) ? self::indianGrouping($whole) : number_format($whole);

        return ($negative ? '-' : '').$this->symbol($currency).$grouped.sprintf('.%02d', $absolute % 100);
    }

    public static function indianGrouping(int $whole): string
    {
        $digits = (string) $whole;

        if (strlen($digits) <= 3) {
            return $digits;
        }

        $last = substr($digits, -3);
        $rest = substr($digits, 0, -3);

        return strrev(implode(',', str_split(strrev($rest), 2))).','.$last;
    }

    private function symbol(string $currency): string
    {
        if (isset(self::PDF_SYMBOLS[$currency])) {
            return self::PDF_SYMBOLS[$currency];
        }

        return $this->prefixes[$currency] ??= (($prefix = (string) Currency::query()->where('code', $currency)->value('prefix')) !== '' ? $prefix : $currency.' ');
    }
}
