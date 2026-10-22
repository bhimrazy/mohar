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

    /** Backward-compatible names that staff and older installs still use, and the zone each stands for. */
    private const ALIASES = [
        'Asia/Katmandu' => 'Asia/Kathmandu',
        'Asia/Calcutta' => 'Asia/Kolkata',
        'Etc/UTC' => 'UTC',
        'Etc/GMT' => 'UTC',
        'Europe/Kiev' => 'Europe/Kyiv',
        'Asia/Saigon' => 'Asia/Ho_Chi_Minh',
        'Asia/Rangoon' => 'Asia/Yangon',
    ];

    /** @var array<string, bool>|null */
    private static ?array $known = null;

    /** @var array<string, true> */
    private static array $reported = [];

    public function timezone(): string
    {
        $configured = trim((string) ($this->settings->get('system.timezone') ?: config('app.timezone', 'UTC')));
        $resolved = $this->resolve($configured);

        if ($resolved !== null) {
            return $resolved;
        }

        if (! isset(self::$reported[$configured])) {
            self::$reported[$configured] = true;
            // Dates fall back to UTC, which is the wrong day for part of every day (ADR 0013). Fiscal notes must refuse
            // to be issued while this is true (isFallback()); the dashboard shows a warning meanwhile.
            report(new \RuntimeException("The company time zone \"{$configured}\" is not a valid time zone; dates use UTC."));
        }

        return 'UTC';
    }

    /** True when the stored company time zone is not valid and dates use UTC. */
    public function isFallback(): bool
    {
        return $this->resolve(trim((string) ($this->settings->get('system.timezone') ?: config('app.timezone', 'UTC')))) === null;
    }

    /** The canonical identifier for a stored name (backward-compatible names included), or null when invalid. */
    private function resolve(string $name): ?string
    {
        self::$known ??= array_fill_keys(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);

        if (isset(self::ALIASES[$name]) && isset(self::$known[self::ALIASES[$name]])) {
            return self::ALIASES[$name];
        }

        return isset(self::$known[$name]) ? $name : null;
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    public function today(): CarbonImmutable
    {
        return $this->now()->startOfDay();
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
