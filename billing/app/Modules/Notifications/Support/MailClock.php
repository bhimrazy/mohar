<?php

namespace App\Modules\Notifications\Support;

use App\Modules\Core\Support\BusinessCalendar;
use App\Modules\Core\Support\DateFormats;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * The one date and time format in email: the "Date format" setting, times as 24-hour H:i with the zone, always in
 * the company's time zone (system.timezone). Calendar dates (Y-m-d: due dates, run dates) are shown as they are;
 * timestamps are converted from UTC.
 */
final class MailClock
{
    public static function timezone(): string
    {
        return rescue(fn (): string => app(BusinessCalendar::class)->timezone(), 'UTC', report: false);
    }

    /**
     * The company's calendar date today (Y-m-d).
     */
    public static function today(): string
    {
        return CarbonImmutable::now(self::timezone())->toDateString();
    }

    public static function date(mixed $value, ?string $format = null, string $locale = 'en'): ?string
    {
        $date = self::parse($value)?->locale($locale);

        return $date instanceof CarbonImmutable ? $date->translatedFormat($format ?? DateFormats::current()) : null;
    }

    public static function dateTime(mixed $value, string $locale = 'en'): ?string
    {
        $date = self::parse($value)?->locale($locale);

        return $date instanceof CarbonImmutable ? $date->translatedFormat(DateFormats::current().', H:i T') : null;
    }

    /**
     * Whole days from the company's today to $value (negative when it is past); null when not a date.
     */
    public static function daysFromToday(mixed $value): ?int
    {
        $date = self::parse($value);

        return $date === null ? null : (int) CarbonImmutable::parse(self::today())->diffInDays(CarbonImmutable::parse($date->toDateString()), false);
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->setTimezone(self::timezone());
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            // A calendar date stays that date; a timestamp (stored in UTC) is shown in company time.
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1
                ? CarbonImmutable::parse(trim($value), self::timezone())
                : CarbonImmutable::parse($value, 'UTC')->setTimezone(self::timezone());
        } catch (Throwable) {
            return null;
        }
    }
}
