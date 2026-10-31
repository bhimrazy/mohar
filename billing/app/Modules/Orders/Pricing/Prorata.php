<?php

namespace App\Modules\Orders\Pricing;

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * First-period proration aligned to a fixed day of the month (catalog.md §3.4), used at checkout. Inputs come from
 * the product's prorata settings (ProductSummary::$prorataDay / $prorataChargeNextMonthDay). The partial divides by
 * the length of the cycle that ends on the anchor, so a longer cycle is not charged per month. Setup fees are never
 * prorated.
 */
final class Prorata
{
    /**
     * The first billed period of a recurring line ordered on $orderDate (a company-calendar date). Without prorata
     * settings it is one full cycle anchored on the order day; with them it ends on the prorata anchor. Null for
     * one-time and free cycles, which have no period.
     */
    public static function firstPeriod(CarbonInterface $orderDate, int $recurring, BillingCycle $cycle, ?int $prorataDay = null, ?int $chargeNextMonthDay = null): ?FirstPeriod
    {
        if (! $cycle->isRecurring()) {
            return null;
        }

        $start = CarbonImmutable::instance($orderDate)->startOfDay();

        if ($prorataDay === null) {
            return new FirstPeriod($start, $cycle->advance($start, anchorDay: $start->day), $start->day, $recurring, prorated: false);
        }

        $result = self::calculate($start, $recurring, $cycle, $prorataDay, $chargeNextMonthDay ?? 32);

        return new FirstPeriod($start, $result['next_due_date'], $prorataDay, $result['first_amount'], prorated: ! $result['anchor']->equalTo($start));
    }

    /**
     * @param  int  $prorataDay  1-31, clamped to the month length
     * @param  int  $chargeNextMonthDay  0 = always add the next full cycle, 32 = never
     * @return array{anchor: CarbonImmutable, partial: int, first_amount: int, next_due_date: CarbonImmutable}
     */
    public static function calculate(CarbonInterface $orderDate, int $recurring, BillingCycle $cycle, int $prorataDay, int $chargeNextMonthDay): array
    {
        $orderDate = CarbonImmutable::instance($orderDate)->startOfDay();
        $months = $cycle->months();

        if ($months === null) {
            return ['anchor' => $orderDate, 'partial' => 0, 'first_amount' => $recurring, 'next_due_date' => $orderDate];
        }

        $anchor = self::anchor($orderDate, $prorataDay, $months === 1);

        if ($anchor->equalTo($orderDate)) {
            return ['anchor' => $anchor, 'partial' => 0, 'first_amount' => $recurring, 'next_due_date' => $cycle->advance($anchor, anchorDay: $prorataDay)];
        }

        $periodStart = $anchor->startOfMonth()->subMonths($months);
        $periodStart = $periodStart->setDay(min($prorataDay, $periodStart->daysInMonth));
        $partial = self::divideRounded(
            $recurring * (int) $orderDate->diffInDays($anchor),
            (int) $periodStart->diffInDays($anchor),
        );

        $addFullCycle = $months !== 1 || $orderDate->day > $chargeNextMonthDay;

        return $addFullCycle
            ? ['anchor' => $anchor, 'partial' => $partial, 'first_amount' => $partial + $recurring, 'next_due_date' => $cycle->advance($anchor, anchorDay: $prorataDay)]
            : ['anchor' => $anchor, 'partial' => $partial, 'first_amount' => $partial, 'next_due_date' => $anchor];
    }

    /**
     * Monthly: next occurrence of the prorata day on/after the order date. Longer cycles: the prorata day
     * of the month after the order.
     */
    private static function anchor(CarbonImmutable $orderDate, int $prorataDay, bool $monthly): CarbonImmutable
    {
        if ($monthly) {
            $thisMonth = $orderDate->setDay(min($prorataDay, $orderDate->daysInMonth));

            if ($thisMonth->greaterThanOrEqualTo($orderDate)) {
                return $thisMonth;
            }
        }

        $nextMonth = $orderDate->startOfMonth()->addMonth();

        return $nextMonth->setDay(min($prorataDay, $nextMonth->daysInMonth));
    }

    /**
     * Integer division rounded half away from zero (minor units).
     */
    private static function divideRounded(int $numerator, int $denominator): int
    {
        $sign = $numerator < 0 ? -1 : 1;

        return $sign * intdiv(2 * abs($numerator) + $denominator, 2 * $denominator);
    }
}
