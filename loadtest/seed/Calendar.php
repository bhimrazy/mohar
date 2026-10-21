<?php

declare(strict_types=1);

namespace LoadTest\Seed;

/**
 * Integer day arithmetic for the bulk seeder: days since 1970-01-01 (UTC), no objects in the hot loop.
 * advanceMonths() mirrors App\Enums\BillingCycle::advance() (start of month + N months, then the anchor day
 * clamped to the month length); run.php --self-test compares the two over many dates.
 */
final class Calendar
{
    public static function toDays(int $year, int $month, int $day): int
    {
        // Howard Hinnant's days_from_civil.
        $year -= $month <= 2 ? 1 : 0;
        $era = intdiv($year >= 0 ? $year : $year - 399, 400);
        $yoe = $year - $era * 400;
        $doy = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /**
     * @return array{0: int, 1: int, 2: int} year, month, day
     */
    public static function fromDays(int $days): array
    {
        $days += 719468;
        $era = intdiv($days >= 0 ? $days : $days - 146096, 146097);
        $doe = $days - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $year = $yoe + $era * 400;
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $day = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $month = $mp + ($mp < 10 ? 3 : -9);

        return [$year + ($month <= 2 ? 1 : 0), $month, $day];
    }

    public static function daysInMonth(int $year, int $month): int
    {
        return match ($month) {
            2 => ($year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0)) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    public static function advanceMonths(int $days, int $months, ?int $anchorDay): int
    {
        [$year, $month, $day] = self::fromDays($days);
        $total = $year * 12 + ($month - 1) + $months;
        $targetYear = intdiv($total, 12);
        $targetMonth = $total % 12 + 1;

        return self::toDays($targetYear, $targetMonth, min($anchorDay ?? $day, self::daysInMonth($targetYear, $targetMonth)));
    }

    public static function date(int $days): string
    {
        [$year, $month, $day] = self::fromDays($days);

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public static function dateTime(int $days, int $secondOfDay = 0): string
    {
        return self::date($days).sprintf(' %02d:%02d:%02d', intdiv($secondOfDay, 3600), intdiv($secondOfDay % 3600, 60), $secondOfDay % 60);
    }

    public static function today(): int
    {
        return intdiv(time(), 86400);
    }
}
