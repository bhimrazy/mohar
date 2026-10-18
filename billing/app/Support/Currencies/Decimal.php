<?php

namespace App\Support\Currencies;

use InvalidArgumentException;
use OverflowException;

/**
 * Exact decimal arithmetic on numeric strings for exchange rates ("134.400000", "1.1253"), without floats
 * and without bcmath: values are held as integer mantissa + scale, and division is long division with
 * half-up rounding. Mantissas are limited to 18 significant digits, which covers every real-world rate.
 */
final class Decimal
{
    private const PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)$/';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * A well-formed, strictly positive number with at most $maxDigits significant digits: safe to feed into
     * multiply/divide without overflow. Use it to vet values from outside (rate feeds) before any arithmetic.
     */
    public static function isPlausible(string $value, int $maxDigits = 9): bool
    {
        $value = trim($value);

        if (! self::isValid($value) || str_starts_with($value, '-')) {
            return false;
        }

        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '+'), 2), 2, '');
        $digits = trim($whole.$fraction, '0');

        // Count significant digits: drop leading zeros of the whole number and trailing zeros of the fraction.
        $significant = ltrim($whole.rtrim($fraction, '0'), '0');

        return $digits !== '' && strlen($significant) <= $maxDigits;
    }

    /**
     * Canonical string with exactly $scale decimals, rounded half up (away from zero).
     */
    public static function round(string $value, int $scale): string
    {
        return self::divide($value, '1', $scale);
    }

    public static function add(string $a, string $b): string
    {
        [$ma, $sa] = self::parse($a);
        [$mb, $sb] = self::parse($b);
        $scale = max($sa, $sb);

        return self::toString(self::scaleUp($ma, $scale - $sa) + self::scaleUp($mb, $scale - $sb), $scale);
    }

    public static function subtract(string $a, string $b): string
    {
        return self::add($a, self::negate($b));
    }

    public static function multiply(string $a, string $b): string
    {
        [$ma, $sa] = self::parse($a);
        [$mb, $sb] = self::parse($b);

        if ($ma !== 0 && abs($mb) > intdiv(PHP_INT_MAX, abs($ma))) {
            throw new OverflowException('Decimal multiplication overflow.');
        }

        return self::toString($ma * $mb, $sa + $sb);
    }

    /**
     * a ÷ b with $scale decimals, rounded half up (away from zero).
     */
    public static function divide(string $a, string $b, int $scale): string
    {
        [$numerator, $sa] = self::parse($a);
        [$denominator, $sb] = self::parse($b);

        if ($denominator === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        $negative = ($numerator < 0) !== ($denominator < 0) && $numerator !== 0;
        $numerator = abs($numerator);
        $denominator = abs($denominator);

        // a/b = (Na / Nb) × 10^(sb − sa); we want floor(a/b × 10^scale) and the remainder for rounding.
        $shift = $sb - $sa + $scale;

        if ($shift < 0) {
            $denominator = self::scaleUp($denominator, -$shift);
            $shift = 0;
        }

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        for ($i = 0; $i < $shift; $i++) {
            if ($quotient > intdiv(PHP_INT_MAX - 9, 10) || $remainder > intdiv(PHP_INT_MAX, 10)) {
                throw new OverflowException('Decimal division overflow.');
            }

            $remainder *= 10;
            $quotient = $quotient * 10 + intdiv($remainder, $denominator);
            $remainder %= $denominator;
        }

        if ($remainder >= $denominator - $remainder) {
            $quotient++;
        }

        return self::toString($negative ? -$quotient : $quotient, $scale);
    }

    public static function compare(string $a, string $b): int
    {
        [$ma, $sa] = self::parse($a);
        [$mb, $sb] = self::parse($b);
        $scale = max($sa, $sb);

        return self::scaleUp($ma, $scale - $sa) <=> self::scaleUp($mb, $scale - $sb);
    }

    public static function abs(string $value): string
    {
        return ltrim(self::canonical($value), '-');
    }

    public static function negate(string $value): string
    {
        $value = self::canonical($value);

        return str_starts_with($value, '-') ? substr($value, 1) : (self::compare($value, '0') === 0 ? $value : '-'.$value);
    }

    public static function isPositive(string $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    /**
     * Relative change from $old to $new in percent, e.g. ("100", "112") → "12.0000".
     */
    public static function percentChange(string $old, string $new, int $scale = 4): string
    {
        return self::divide(self::multiply(self::subtract($new, $old), '100'), $old, $scale);
    }

    private static function canonical(string $value): string
    {
        [$mantissa, $scale] = self::parse($value);

        return self::toString($mantissa, $scale);
    }

    /**
     * @return array{0: int, 1: int} mantissa and scale, trailing fraction zeros removed
     */
    private static function parse(string $value): array
    {
        $value = trim($value);

        if (! self::isValid($value)) {
            throw new InvalidArgumentException("Not a decimal number [{$value}].");
        }

        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '+-'), 2), 2, '');
        $fraction = rtrim($fraction, '0');
        $digits = ltrim($whole.$fraction, '0');

        if (strlen($digits) > 18) {
            throw new OverflowException("Decimal [{$value}] has more than 18 significant digits.");
        }

        $mantissa = (int) ($digits === '' ? '0' : $digits);

        return [$negative ? -$mantissa : $mantissa, strlen($fraction)];
    }

    private static function scaleUp(int $mantissa, int $places): int
    {
        for ($i = 0; $i < $places; $i++) {
            if (abs($mantissa) > intdiv(PHP_INT_MAX, 10)) {
                throw new OverflowException('Decimal scale overflow.');
            }

            $mantissa *= 10;
        }

        return $mantissa;
    }

    private static function toString(int $mantissa, int $scale): string
    {
        $sign = $mantissa < 0 ? '-' : '';
        $digits = str_pad((string) abs($mantissa), $scale + 1, '0', STR_PAD_LEFT);

        if ($scale === 0) {
            return $sign.$digits;
        }

        return $sign.substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }
}
