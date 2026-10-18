<?php

namespace App\Modules\Services\Support;

use Carbon\CarbonImmutable;

/**
 * Day-based proration for upgrades/downgrades (services.md §5). Pure integer arithmetic in minor
 * units; each product is rounded half-up.
 */
final readonly class Proration
{
    public function __construct(
        public int $cycleDays,
        public int $remainingDays,
        public int $credit,
        public int $charge,
    ) {}

    public static function calculate(int $oldAmount, int $newAmount, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $today): self
    {
        $cycleDays = max(1, (int) $periodStart->startOfDay()->diffInDays($periodEnd->startOfDay()));
        $remainingDays = min($cycleDays, max(0, (int) $today->startOfDay()->diffInDays($periodEnd->startOfDay(), false)));

        return new self(
            $cycleDays,
            $remainingDays,
            self::divideHalfUp($oldAmount * $remainingDays, $cycleDays),
            self::divideHalfUp($newAmount * $remainingDays, $cycleDays),
        );
    }

    /**
     * A change with no proration on the old side (free → paid): full new price, no credit.
     */
    public static function fullCharge(int $newAmount, int $credit = 0): self
    {
        return new self(0, 0, $credit, $newAmount);
    }

    public function amountDue(): int
    {
        return $this->charge - $this->credit;
    }

    /**
     * round($numerator / $denominator) half away from zero, without floats.
     */
    public static function divideHalfUp(int $numerator, int $denominator): int
    {
        $sign = ($numerator < 0) !== ($denominator < 0) ? -1 : 1;
        $numerator = abs($numerator);
        $denominator = abs($denominator);

        return $sign * intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
