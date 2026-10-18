<?php

namespace App\Modules\Billing\Services\Tax;

/**
 * Pure tax arithmetic in integer minor units (§5). No framework dependencies.
 *
 * Rates are thousandths of a percent (20% = 20000) so all maths stays integer.
 */
final class TaxMath
{
    private const SCALE = 100_000;

    /**
     * @param  list<array{amount: int, taxable: bool}>  $lines  amounts are net (exclusive) or gross (inclusive)
     */
    public static function compute(array $lines, TaxRates $rates): TaxResult
    {
        $lineTaxes = array_fill(0, count($lines), 0);

        if (! $rates->hasTax()) {
            $sum = array_sum(array_column($lines, 'amount'));

            return new TaxResult($lineTaxes, $sum, 0, 0, $sum);
        }

        if (! $rates->perInvoice) {
            $subtotal = $tax1 = $tax2 = 0;

            foreach ($lines as $index => $line) {
                [$net, $lineTax1, $lineTax2] = $line['taxable'] ? self::split($line['amount'], $rates) : [$line['amount'], 0, 0];
                $subtotal += $net;
                $tax1 += $lineTax1;
                $tax2 += $lineTax2;
                $lineTaxes[$index] = $lineTax1 + $lineTax2;
            }

            return new TaxResult(array_values($lineTaxes), $subtotal, $tax1, $tax2, $subtotal + $tax1 + $tax2);
        }

        $taxable = $untaxed = 0;

        foreach ($lines as $line) {
            $line['taxable'] ? $taxable += $line['amount'] : $untaxed += $line['amount'];
        }

        [$net, $tax1, $tax2] = self::split($taxable, $rates);
        $lineTaxes = self::allocate($tax1 + $tax2, $lines);
        $subtotal = $net + $untaxed;

        return new TaxResult($lineTaxes, $subtotal, $tax1, $tax2, $subtotal + $tax1 + $tax2);
    }

    /**
     * Split one amount into [net, tax1, tax2]. Inclusive rounding remainders go to net so gross is preserved.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function split(int $amount, TaxRates $rates): array
    {
        $r1 = $rates->rate1;
        $r2 = $rates->rate2;
        $mode = $rates->rounding;

        if (! $rates->inclusive) {
            $tax1 = self::roundDiv($amount * $r1, self::SCALE, $mode);
            $tax2 = self::roundDiv(($rates->compound ? $amount + $tax1 : $amount) * $r2, self::SCALE, $mode);

            return [$amount, $tax1, $tax2];
        }

        if ($r2 === 0) {
            $tax1 = self::roundDiv($amount * $r1, self::SCALE + $r1, $mode);

            return [$amount - $tax1, $tax1, 0];
        }

        $net = $rates->compound
            ? self::roundDiv($amount * self::SCALE * self::SCALE, (self::SCALE + $r1) * (self::SCALE + $r2), $mode)
            : self::roundDiv($amount * self::SCALE, self::SCALE + $r1 + $r2, $mode);
        $tax1 = self::roundDiv($net * $r1, self::SCALE, $mode);
        $tax2 = self::roundDiv(($rates->compound ? $net + $tax1 : $net) * $r2, self::SCALE, $mode);

        return [$amount - $tax1 - $tax2, $tax1, $tax2];
    }

    /**
     * Integer division with an explicit rounding mode. A float numerator (int overflow) falls back to float maths.
     */
    public static function roundDiv(int|float $numerator, int $denominator, string $mode = 'half_up'): int
    {
        if (is_float($numerator)) {
            $value = $numerator / $denominator;

            return match ($mode) {
                'down' => (int) $value,
                'up' => (int) ($value < 0 ? floor($value) : ceil($value)),
                'half_even' => (int) round($value, 0, PHP_ROUND_HALF_EVEN),
                default => (int) round($value, 0, PHP_ROUND_HALF_UP),
            };
        }

        if ($denominator < 0) {
            [$numerator, $denominator] = [-$numerator, -$denominator];
        }

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        if ($remainder === 0) {
            return $quotient;
        }

        $sign = $numerator < 0 ? -1 : 1;
        $twice = abs($remainder) * 2;

        return match ($mode) {
            'down' => $quotient,
            'up' => $quotient + $sign,
            'half_even' => $twice > $denominator || ($twice === $denominator && $quotient % 2 !== 0) ? $quotient + $sign : $quotient,
            default => $twice >= $denominator ? $quotient + $sign : $quotient,
        };
    }

    /**
     * Spread an invoice-level tax over the taxable lines proportionally (largest remainder), for per_invoice mode.
     *
     * @param  list<array{amount: int, taxable: bool}>  $lines
     * @return list<int>
     */
    private static function allocate(int $tax, array $lines): array
    {
        $weights = array_map(fn (array $line): int => $line['taxable'] ? $line['amount'] : 0, $lines);
        $base = array_sum($weights);
        $shares = array_fill(0, count($lines), 0);

        if ($base === 0 || $tax === 0) {
            return $shares;
        }

        $assigned = 0;

        foreach ($weights as $index => $weight) {
            $shares[$index] = intdiv($tax * $weight, $base);
            $assigned += $shares[$index];
        }

        $remainder = $tax - $assigned;
        $step = $remainder > 0 ? 1 : -1;

        foreach (array_keys(array_filter($weights, fn (int $weight): bool => $weight > 0)) as $index) {
            if ($remainder === 0) {
                break;
            }

            $shares[$index] += $step;
            $remainder -= $step;
        }

        return array_values($shares);
    }
}
