<?php

namespace App\Modules\Catalog\Support;

use App\Data\Catalog\PriceQuote;
use App\Enums\BillingCycle;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Pure pricing math for quotes. Amounts are integer minor units; no floats are persisted.
 */
final class PriceCalculator
{
    /**
     * Sum price lines into a quote. A client-group discount is applied after options, rounded half-up per line,
     * and added to the breakdown as a negative line so the breakdown always sums to the totals.
     *
     * @param  list<array{label: string, recurring: int, setup: int}>  $lines  base price first, then each option
     */
    public static function quote(string $currency, BillingCycle $cycle, array $lines, float|int|string $discountPercent = 0): PriceQuote
    {
        $currency = strtoupper($currency);
        $discountPercent = (float) $discountPercent;

        if ($discountPercent < 0 || $discountPercent > 100) {
            throw new InvalidArgumentException("Discount must be between 0 and 100, [{$discountPercent}] given.");
        }

        $recurring = 0;
        $setup = 0;
        $recurringDiscount = 0;
        $setupDiscount = 0;

        foreach ($lines as $line) {
            $recurring += $line['recurring'];
            $setup += $line['setup'];

            if ($discountPercent > 0) {
                $recurringDiscount += Money::of($line['recurring'], $currency)->percent($discountPercent)->amount;
                $setupDiscount += Money::of($line['setup'], $currency)->percent($discountPercent)->amount;
            }
        }

        if ($recurringDiscount !== 0 || $setupDiscount !== 0) {
            $lines[] = [
                'label' => sprintf('Client discount (%s%%)', rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.')),
                'recurring' => -$recurringDiscount,
                'setup' => -$setupDiscount,
            ];
        }

        return new PriceQuote($currency, $cycle, $recurring - $recurringDiscount, $setup - $setupDiscount, $lines);
    }

    /**
     * Apply a client-group discount to an existing (undiscounted) quote.
     */
    public static function discount(PriceQuote $quote, float|int|string $discountPercent): PriceQuote
    {
        return self::quote($quote->currency, $quote->cycle, $quote->breakdown, $discountPercent);
    }

    /**
     * Charge of a quantity option: the stored price is per unit (recurring and setup).
     *
     * @return array{recurring: int, setup: int}
     */
    public static function quantityCharge(int $unitRecurring, int $unitSetup, int $quantity): array
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException('Quantity cannot be negative.');
        }

        return ['recurring' => $unitRecurring * $quantity, 'setup' => $unitSetup * $quantity];
    }

    /**
     * Whether a quantity respects an option's min/max/step constraints.
     */
    public static function isValidQuantity(int $quantity, ?int $min, ?int $max, int $step = 1): bool
    {
        if ($quantity < 0 || ($min !== null && $quantity < $min) || ($max !== null && $quantity > $max)) {
            return false;
        }

        return $step <= 1 || ($quantity - ($min ?? 0)) % $step === 0;
    }

    /**
     * Equivalent monthly amount of a recurring price, rounded half-up (store "/mo" display).
     */
    public static function monthlyEquivalent(int $amount, BillingCycle $cycle): ?int
    {
        $months = $cycle->months();

        return $months === null ? null : self::divideRounded($amount, $months);
    }

    /**
     * Integer division rounded half away from zero.
     */
    public static function divideRounded(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Denominator must be positive.');
        }

        $sign = $numerator < 0 ? -1 : 1;
        $numerator = abs($numerator);

        return $sign * intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
