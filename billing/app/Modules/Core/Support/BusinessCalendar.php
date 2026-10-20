<?php

namespace App\Modules\Core\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * The company's calendar (Settings → system.timezone) for reports that bucket UTC timestamps into local days.
 * Timestamps are stored in UTC; dates (due dates, run dates) are already calendar dates and need no conversion.
 */
final class BusinessCalendar
{
    public function __construct(private Settings $settings) {}

    public function timezone(): string
    {
        $timezone = (string) ($this->settings->get('system.timezone') ?: config('app.timezone', 'UTC'));

        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    /**
     * Today's local date, at midnight (as a plain date value, like CarbonImmutable::today()).
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now($this->timezone())->toDateString());
    }

    /**
     * Local midnight of a local date, in UTC: the lower bound for queries on UTC timestamp columns.
     */
    public function startOfDayUtc(CarbonImmutable $localDate): CarbonImmutable
    {
        return CarbonImmutable::parse($localDate->toDateString(), $this->timezone())->utc();
    }

    /**
     * The local date (Y-m-d) of a UTC timestamp read from the database.
     */
    public function localDate(mixed $utcTimestamp): ?string
    {
        return $utcTimestamp === null ? null : CarbonImmutable::parse((string) $utcTimestamp, 'UTC')->setTimezone($this->timezone())->toDateString();
    }

    /**
     * The bound argument that turns a UTC timestamp into local time in SQL, per driver: PostgreSQL takes the zone
     * name (`(col at time zone 'UTC') at time zone ?`, daylight saving exact); MySQL an offset (`convert_tz(col,
     * '+00:00', ?)`); SQLite a modifier (`date(col, ?)`). MySQL and SQLite use today's offset.
     */
    public function sqlZoneArgument(): string
    {
        $timezone = $this->timezone();
        $minutes = intdiv(CarbonImmutable::now($timezone)->getOffset(), 60);
        $sign = $minutes < 0 ? '-' : '+';

        return match (DB::getDriverName()) {
            'pgsql' => $timezone,
            'mysql', 'mariadb' => sprintf('%s%02d:%02d', $sign, intdiv(abs($minutes), 60), abs($minutes) % 60),
            default => sprintf('%s%d minutes', $sign, abs($minutes)),
        };
    }

    /**
     * SQL for the local date of a UTC timestamp column, with one `?` to bind to sqlZoneArgument().
     *
     * @param  literal-string  $column  a column name written in code
     * @return literal-string
     */
    public function sqlLocalDate(string $column): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "date(({$column} at time zone 'UTC') at time zone ?)",
            'mysql', 'mariadb' => "date(convert_tz({$column}, '+00:00', ?))",
            default => "date({$column}, ?)",
        };
    }
}
