<?php

namespace App\Support\Automation;

use App\Support\CalendarDate;
use Carbon\CarbonImmutable;
use Closure;
use ReflectionFunction;
use ReflectionNamedType;

/**
 * The daily automation pipeline. Modules register idempotent tasks in bootModule(); Core's
 * `automation:run` command executes them once per day in stage order and records each run.
 *
 *   app(Automation::class)->task('services.renewals', Automation::RENEWALS, fn (CalendarDate $today) => $count,
 *       label: 'Generate renewal invoices', description: 'Invoices services whose next invoice date has come.');
 *
 * Staff-facing screens show the label and description, never the key (the key stays as secondary text).
 *
 * Tasks receive the run date, a company-calendar date (ADR 0013 Amendment 2). A handler whose first parameter is typed
 * `CalendarDate` gets one; any other handler gets the older Carbon shape of `CompanyClock::todayDate()` (00:00 UTC on
 * the company date), until every in-repo task has moved. Turn the run date into instants only with
 * `CompanyClock::dayBounds()`. Name the parameter `$today`, so the arch scan (CompanyClockScanTest) can see it. Tasks
 * return a short summary (int count or string) for the run log.
 * A task must be safe to re-run for the same date (it may be retried after a failure).
 */
class Automation
{
    /** Exchange rates are refreshed first so renewals and converted prices use the day's rates. */
    public const EXCHANGE_RATES = 5;

    public const RENEWALS = 10;

    public const LATE_FEES = 20;

    public const AUTO_CHARGE = 30;

    public const REMINDERS = 40;

    public const CANCELLATIONS = 50;

    public const SUSPENSIONS = 60;

    public const TERMINATIONS = 70;

    public const SYNC = 80;

    public const HOUSEKEEPING = 90;

    /** @var array<string, array{stage: int, handler: Closure, label: string, description: ?string}> */
    private array $tasks = [];

    public function task(string $key, int $stage, Closure $handler, ?string $label = null, ?string $description = null): void
    {
        $this->tasks[$key] = ['stage' => $stage, 'handler' => $handler, 'label' => $label ?? self::fallbackLabel($key), 'description' => $description];
    }

    /**
     * The staff-facing name of a task; tasks that no longer exist (old log rows) get a readable fallback.
     */
    public function label(string $key): string
    {
        return $this->tasks[$key]['label'] ?? self::fallbackLabel($key);
    }

    /**
     * "billing.auto_capture" → "Auto capture (billing)": never a raw key, even for a task registered without a label.
     */
    private static function fallbackLabel(string $key): string
    {
        [$module, $name] = str_contains($key, '.') ? explode('.', $key, 2) : ['', $key];

        return ucfirst(str_replace(['_', '-', '.'], ' ', $name)).($module === '' ? '' : " ({$module})");
    }

    /** Runs one registered task for a run date and returns its summary. */
    public function run(string $key, CalendarDate $date): mixed
    {
        $handler = $this->tasks[$key]['handler'];
        $type = ((new ReflectionFunction($handler))->getParameters()[0] ?? null)?->getType();

        return $handler($type instanceof ReflectionNamedType && $type->getName() === CalendarDate::class
            ? $date
            : CarbonImmutable::parse($date->toString(), 'UTC'));
    }

    /**
     * @return array<string, array{stage: int, handler: Closure, label: string, description: ?string}>
     */
    public function ordered(): array
    {
        $tasks = $this->tasks;
        uasort($tasks, fn (array $a, array $b): int => $a['stage'] <=> $b['stage']);

        return $tasks;
    }
}
