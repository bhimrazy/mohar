<?php

namespace App\Modules\Core\Support;

use App\Contracts\Core\CompanyClock;
use App\Support\CalendarDate;
use Carbon\CarbonImmutable;

/**
 * The run date a retention task counts its window from: the date given, but never after the company today, so
 * `automation:run --date=<future>` (a rehearsal) cannot widen a cutoff and delete rows still inside their window
 * (ADR 0013 Amendment (#460)).
 */
final class RetentionRunDate
{
    public static function of(CalendarDate $today): CalendarDate
    {
        $now = app(CompanyClock::class)->calendarDate();

        return $today->isAfter($now) ? $now : $today;
    }

    /** For tasks still typed with the Carbon run date (00:00 UTC on the company date). */
    public static function ofCarbon(CarbonImmutable $today): CarbonImmutable
    {
        $now = app(CompanyClock::class)->todayDate();

        return $today->greaterThan($now) ? $now : $today;
    }
}
