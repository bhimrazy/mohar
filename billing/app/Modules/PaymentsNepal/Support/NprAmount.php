<?php

namespace App\Modules\PaymentsNepal\Support;

/**
 * Exact conversion between paisa (our minor units) and the rupee decimal strings eSewa uses. Integer and string
 * arithmetic only, never floats: eSewa sends "1,130.0", "100" or 100.0, and all of them must compare exactly.
 */
final class NprAmount
{
    /**
     * 113050 → "1130.50"; whole rupees without decimals (113000 → "1130"), like the provider's own examples.
     */
    public static function toRupees(int $paisa): string
    {
        if ($paisa < 0) {
            throw new \InvalidArgumentException('Amounts sent to the provider must not be negative.');
        }

        $rupees = intdiv($paisa, 100);
        $fraction = $paisa % 100;

        return $fraction === 0 ? (string) $rupees : sprintf('%d.%02d', $rupees, $fraction);
    }

    /**
     * Provider rupee amount → paisa, or null when it isn't a plain non-negative amount with at most 2 significant
     * decimals. Thousands separators are ignored.
     */
    public static function toPaisa(mixed $rupees): ?int
    {
        if (is_int($rupees)) {
            return $rupees >= 0 && $rupees <= intdiv(PHP_INT_MAX, 100) ? $rupees * 100 : null;
        }

        if (is_float($rupees)) {
            // JSON numbers such as 100.0: accept only values that are exact to the paisa.
            if (! is_finite($rupees) || $rupees < 0 || $rupees > 1e12) {
                return null;
            }

            $formatted = number_format($rupees, 2, '.', '');

            return abs((float) $formatted - $rupees) < 0.000001 ? self::toPaisa($formatted) : null;
        }

        if (! is_string($rupees)) {
            return null;
        }

        $clean = str_replace([',', ' '], '', trim($rupees));

        if (preg_match('/^(\d{1,13})(?:\.(\d{1,6}))?$/', $clean, $match) !== 1) {
            return null;
        }

        $fraction = $match[2] ?? '';

        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
            return null;
        }

        return (int) $match[1] * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
