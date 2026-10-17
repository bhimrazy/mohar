<?php

namespace App\Support\Automation;

use Closure;

/**
 * The daily automation pipeline. Modules register idempotent tasks in bootModule(); Core's
 * `automation:run` command executes them once per day in stage order and records each run.
 *
 *   app(Automation::class)->task('services.renewals', Automation::RENEWALS, fn (CarbonImmutable $today) => $count);
 *
 * Tasks receive the run date and return a short summary (int count or string) for the run log.
 * A task must be safe to re-run for the same date (it may be retried after a failure).
 */
class Automation
{
    public const RENEWALS = 10;

    public const LATE_FEES = 20;

    public const AUTO_CHARGE = 30;

    public const REMINDERS = 40;

    public const CANCELLATIONS = 50;

    public const SUSPENSIONS = 60;

    public const TERMINATIONS = 70;

    public const SYNC = 80;

    public const HOUSEKEEPING = 90;

    /** @var array<string, array{stage: int, handler: Closure}> */
    private array $tasks = [];

    public function task(string $key, int $stage, Closure $handler): void
    {
        $this->tasks[$key] = ['stage' => $stage, 'handler' => $handler];
    }

    /**
     * @return array<string, array{stage: int, handler: Closure}>
     */
    public function ordered(): array
    {
        $tasks = $this->tasks;
        uasort($tasks, fn (array $a, array $b): int => $a['stage'] <=> $b['stage']);

        return $tasks;
    }
}
