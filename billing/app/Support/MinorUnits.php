<?php

namespace App\Support;

use App\Contracts\Currencies\CurrencyDirectory;
use InvalidArgumentException;

/**
 * Exact conversion between minor units and the decimal amounts providers send and expect (ADR 0030 A4). Integer and
 * string arithmetic only: an amount that cannot be expressed exactly is refused, never rounded.
 *
 * @api
 */
final class MinorUnits
{
    /** Decimals of our minor units for $currency (Admin → Currencies); 2 when it is not configured. */
    public static function decimals(string $currency): int
    {
        if (! app()->bound(CurrencyDirectory::class)) {
            return 2;
        }

        return app(CurrencyDirectory::class)->display($currency)->decimals ?? 2;
    }

    /**
     * An amount with $from decimals as one with $to decimals (1234 with 2 → 12340 with 3), or null when it overflows
     * or would drop a non-zero digit.
     */
    public static function rescale(int $amount, int $from, int $to): ?int
    {
        $power = $to - $from;

        if ($power === 0) {
            return $amount;
        }

        $factor = self::factor(abs($power));

        if ($power > 0) {
            return $amount > intdiv(PHP_INT_MAX, $factor) ? null : $amount * $factor;
        }

        return $amount % $factor === 0 ? intdiv($amount, $factor) : null;
    }

    /**
     * 113050 with 2 decimals → "1130.50"; whole units without decimals (113000 → "1130").
     */
    public static function toDecimal(int $amount, int $decimals): string
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Amounts sent to the provider must not be negative.');
        }

        $factor = self::factor($decimals);
        $whole = intdiv($amount, $factor);
        $fraction = $amount % $factor;

        return $fraction === 0 ? (string) $whole : sprintf('%d.%0'.$decimals.'d', $whole, $fraction);
    }

    /**
     * A provider's decimal amount → minor units with $decimals, or null when it is not a plain non-negative amount
     * exact to $decimals. Accepts integers, floats exact to the minor unit (JSON 100.0) and strings ("1,130.0");
     * thousands separators are ignored.
     */
    public static function fromDecimal(mixed $value, int $decimals): ?int
    {
        $factor = self::factor($decimals);

        if (is_int($value)) {
            return $value >= 0 && $value <= intdiv(PHP_INT_MAX, $factor) ? $value * $factor : null;
        }

        if (is_float($value)) {
            if (! is_finite($value) || $value < 0 || $value > 1e12) {
                return null;
            }

            $formatted = number_format($value, $decimals, '.', '');

            return abs((float) $formatted - $value) < 0.000001 ? self::fromDecimal($formatted, $decimals) : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $clean = str_replace([',', ' '], '', trim($value));

        if (preg_match('/^(\d{1,13})(?:\.(\d{1,6}))?$/', $clean, $match) !== 1) {
            return null;
        }

        $fraction = $match[2] ?? '';

        if (strlen($fraction) > $decimals && trim(substr($fraction, $decimals), '0') !== '') {
            return null;
        }

        return (int) $match[1] * $factor + (int) str_pad(substr($fraction, 0, $decimals), $decimals, '0');
    }

    /** 10 to the power $decimals, as an integer. */
    private static function factor(int $decimals): int
    {
        $factor = 1;

        for ($i = 0; $i < $decimals; $i++) {
            $factor *= 10;
        }

        return $factor;
    }
}
