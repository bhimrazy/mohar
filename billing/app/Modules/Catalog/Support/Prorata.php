<?php

namespace App\Modules\Catalog\Support;

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * First-period proration aligned to a fixed day of the month (catalog spec §3.4).
 *
 * Orders performs proration at checkout; this class holds the calculation so the rule lives with the
 * product settings it reads (prorata_day, prorata_charge_next_month_day). Setup fees are never prorated.
 */
final class Prorata
{
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
        $partial = PriceCalculator::divideRounded(
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
}
