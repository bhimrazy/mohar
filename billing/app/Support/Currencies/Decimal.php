<?php

namespace App\Support\Currencies;

use InvalidArgumentException;
use OverflowException;

/**
 * Exact decimal arithmetic on numeric strings for exchange rates and conversions ("134.400000000000",
 * "1.1253"), without floats and without bcmath: values are held as a sign, a digit string and a scale, and
 * every operation works on digit strings (schoolbook add/multiply, long division with half-up rounding), so
 * decimal(24,12) rates and their products never overflow. Inputs are limited to 200 digits as a safety cap.
 */
final class Decimal
{
    private const PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)$/';

    private const MAX_DIGITS = 200;

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * A well-formed, strictly positive number with at most $maxDigits significant digits. Use it to vet values
     * from outside (rate feeds) before any arithmetic.
     */
    public static function isPlausible(string $value, int $maxDigits = 12): bool
    {
        $value = trim($value);

        if (! self::isValid($value) || str_starts_with($value, '-')) {
            return false;
        }

        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '+'), 2), 2, '');
        $significant = trim($whole.rtrim($fraction, '0'), '0');

        return $significant !== '' && strlen(ltrim($whole.rtrim($fraction, '0'), '0')) <= $maxDigits;
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
        [$na, $da, $sa] = self::parse($a);
        [$nb, $db, $sb] = self::parse($b);
        $scale = max($sa, $sb);
        $da = self::shift($da, $scale - $sa);
        $db = self::shift($db, $scale - $sb);

        if ($na === $nb) {
            return self::toString($na, self::addDigits($da, $db), $scale);
        }

        // Opposite signs: subtract the smaller magnitude from the larger and keep the larger one's sign.
        return self::compareDigits($da, $db) >= 0
            ? self::toString($na, self::subtractDigits($da, $db), $scale)
            : self::toString($nb, self::subtractDigits($db, $da), $scale);
    }

    public static function subtract(string $a, string $b): string
    {
        return self::add($a, self::negate($b));
    }

    public static function multiply(string $a, string $b): string
    {
        [$na, $da, $sa] = self::parse($a);
        [$nb, $db, $sb] = self::parse($b);

        return self::toString($na !== $nb, self::multiplyDigits($da, $db), $sa + $sb);
    }

    /**
     * a ÷ b with $scale decimals, rounded half up (away from zero).
     */
    public static function divide(string $a, string $b, int $scale): string
    {
        [$na, $numerator, $sa] = self::parse($a);
        [$nb, $denominator, $sb] = self::parse($b);

        if ($denominator === '0') {
            throw new InvalidArgumentException('Division by zero.');
        }

        // a/b = (Na / Nb) × 10^(sb − sa); we want floor(a/b × 10^scale) and the remainder for rounding.
        $shift = $sb - $sa + $scale;
        $numerator = self::shift($numerator, max(0, $shift));
        $denominator = self::shift($denominator, max(0, -$shift));

        [$quotient, $remainder] = self::divideDigits($numerator, $denominator);

        if (self::compareDigits(self::addDigits($remainder, $remainder), $denominator) >= 0) {
            $quotient = self::addDigits($quotient, '1');
        }

        return self::toString($na !== $nb, $quotient, $scale);
    }

    public static function compare(string $a, string $b): int
    {
        [$na, $da, $sa] = self::parse($a);
        [$nb, $db, $sb] = self::parse($b);
        $scale = max($sa, $sb);
        $da = self::shift($da, $scale - $sa);
        $db = self::shift($db, $scale - $sb);
        $na = $na && $da !== '0';
        $nb = $nb && $db !== '0';

        if ($na !== $nb) {
            return $na ? -1 : 1;
        }

        $magnitude = self::compareDigits($da, $db);

        return $na ? -$magnitude : $magnitude;
    }

    public static function abs(string $value): string
    {
        [, $digits, $scale] = self::parse($value);

        return self::toString(false, $digits, $scale);
    }

    public static function negate(string $value): string
    {
        [$negative, $digits, $scale] = self::parse($value);

        return self::toString(! $negative, $digits, $scale);
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

    /**
     * @return array{0: bool, 1: string, 2: int} negative, digits (no leading zeros, "0" for zero), scale
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

        if (strlen($digits) > self::MAX_DIGITS) {
            throw new OverflowException('Decimal has more than '.self::MAX_DIGITS.' digits.');
        }

        return [$negative, $digits === '' ? '0' : $digits, strlen($fraction)];
    }

    private static function shift(string $digits, int $places): string
    {
        return $digits === '0' || $places <= 0 ? $digits : $digits.str_repeat('0', $places);
    }

    private static function toString(bool $negative, string $digits, int $scale): string
    {
        $sign = $negative && $digits !== '0' ? '-' : '';
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);

        if ($scale === 0) {
            return $sign.$digits;
        }

        return $sign.substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    private static function normalize(string $digits): string
    {
        $digits = ltrim($digits, '0');

        return $digits === '' ? '0' : $digits;
    }

    private static function compareDigits(string $a, string $b): int
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }

    private static function addDigits(string $a, string $b): string
    {
        $result = '';
        $carry = 0;

        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry > 0; $i--, $j--) {
            $sum = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return self::normalize($result);
    }

    /**
     * a − b for a ≥ b.
     */
    private static function subtractDigits(string $a, string $b): string
    {
        $result = '';
        $borrow = 0;

        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0; $i--, $j--) {
            $difference = (int) $a[$i] - ($j >= 0 ? (int) $b[$j] : 0) - $borrow;
            $borrow = $difference < 0 ? 1 : 0;
            $result = ($difference + 10 * $borrow).$result;
        }

        return self::normalize($result);
    }

    private static function multiplyDigits(string $a, string $b): string
    {
        if ($a === '0' || $b === '0') {
            return '0';
        }

        $lengthA = strlen($a);
        $lengthB = strlen($b);
        $product = array_fill(0, $lengthA + $lengthB, 0);

        for ($i = $lengthA - 1; $i >= 0; $i--) {
            for ($j = $lengthB - 1; $j >= 0; $j--) {
                $position = $i + $j + 1;
                $sum = (int) $a[$i] * (int) $b[$j] + $product[$position];
                $product[$position] = $sum % 10;
                $product[$position - 1] += intdiv($sum, 10);
            }
        }

        return self::normalize(implode('', $product));
    }

    /**
     * Long division of digit strings.
     *
     * @return array{0: string, 1: string} quotient and remainder
     */
    private static function divideDigits(string $numerator, string $denominator): array
    {
        $quotient = '';
        $remainder = '0';
        $length = strlen($numerator);

        for ($i = 0; $i < $length; $i++) {
            $remainder = self::normalize($remainder.$numerator[$i]);
            $digit = 0;

            while (self::compareDigits($remainder, $denominator) >= 0) {
                $remainder = self::subtractDigits($remainder, $denominator);
                $digit++;
            }

            $quotient .= $digit;
        }

        return [self::normalize($quotient), $remainder];
    }
}
