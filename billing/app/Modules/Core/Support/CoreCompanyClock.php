<?php

namespace App\Modules\Core\Support;

use App\Contracts\Core\CompanyClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Core's CompanyClock (ADR 0013): the company's calendar from Settings → `system.timezone`. Timestamps are stored in
 * UTC; dates (due dates, run dates) are calendar dates and need no conversion. The SQL helpers below are Core-internal
 * and go once the two dashboards that use them query with dayBounds().
 */
final class CoreCompanyClock implements CompanyClock
{
    public function __construct(private Settings $settings) {}

    public function timezone(): string
    {
        $timezone = (string) ($this->settings->get('system.timezone') ?: config('app.timezone', 'UTC'));

        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->now()->toDateString());
    }

    public function dayBounds(string|CarbonInterface $date): array
    {
        $day = $date instanceof CarbonInterface ? $date->format('Y-m-d') : CarbonImmutable::parse($date)->format('Y-m-d');
        $start = CarbonImmutable::parse($day, $this->timezone());

        return [$start->utc(), $start->addDay()->utc()];
    }

    public function localDate(CarbonInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone($this->timezone())->toDateString();
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
