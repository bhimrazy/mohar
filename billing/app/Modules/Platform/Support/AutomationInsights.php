<?php

namespace App\Modules\Platform\Support;

use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Support\AutomationStatus;
use App\Modules\Core\Support\Settings;
use App\Support\Automation\Automation;
use App\Support\Dashboard\Trend;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Summary of the daily automation pipeline for the Automation page: scheduler heartbeat, success rate, failures,
 * the next run, durations, items processed today and runs per day. Built from one query over the last 30 days of
 * `automation_runs` plus the scheduler heartbeat.
 *
 * @phpstan-type Run array{day: string, task: string, status: string, summary: string|null, duration_ms: int, updated_at: CarbonImmutable|null}
 */
class AutomationInsights
{
    public const DAYS = 30;

    /** A heartbeat younger than this is on time (the scheduler ticks every minute). */
    public const LATE_AFTER_MINUTES = 5;

    /** A heartbeat older than this means the scheduler has stopped. */
    public const STOPPED_AFTER_MINUTES = 60;

    public function __construct(private AutomationStatus $status, private Automation $automation, private Settings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $today = CarbonImmutable::today();
        $since = $today->subDays(self::DAYS - 1);
        $tasks = $this->automation->ordered();
        $runs = AutomationRun::query()
            ->where('run_date', '>=', $since->toDateString())
            ->orderBy('id')
            ->toBase()
            ->get(['run_date', 'task', 'status', 'summary', 'duration_ms', 'updated_at'])
            ->map(fn (object $run): array => [
                'day' => substr((string) $run->run_date, 0, 10),
                'task' => (string) $run->task,
                'status' => (string) $run->status,
                'summary' => $run->summary === null ? null : (string) $run->summary,
                'duration_ms' => (int) $run->duration_ms,
                'updated_at' => $run->updated_at === null ? null : CarbonImmutable::parse((string) $run->updated_at),
            ]);

        $nextRun = $this->nextRun($today);

        return [
            'hasRuns' => $runs->isNotEmpty() || AutomationRun::query()->exists(),
            'scheduler' => $this->scheduler(),
            'successRate' => $this->successRate($runs, $today),
            'failed24h' => $runs->filter(fn (array $run): bool => $run['status'] === 'failed' && $run['updated_at']?->gte(now()->subDay()))->count(),
            'nextRunAt' => $nextRun->toIso8601String(),
            'duration' => $this->duration($runs, $today),
            'perDay' => $this->perDay($runs, $since, count($tasks), todayPending: $nextRun->isSameDay($today)),
            'processedToday' => $this->processedToday($runs, $today, $tasks),
            'stageDurations' => $this->stageDurations($runs, $today, $tasks),
            'cronCommand' => sprintf('* * * * * cd %s && php artisan schedule:run >> /dev/null 2>&1', base_path()),
        ];
    }

    /**
     * @return array{state: string, heartbeatAt: string|null}
     */
    private function scheduler(): array
    {
        $heartbeat = $this->status->heartbeatAt();
        $minutes = $heartbeat === null ? null : $heartbeat->diffInMinutes(now());

        return [
            'state' => match (true) {
                $minutes === null => 'never',
                $minutes < self::LATE_AFTER_MINUTES => 'ok',
                $minutes < self::STOPPED_AFTER_MINUTES => 'late',
                default => 'stopped',
            },
            'heartbeatAt' => $heartbeat?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, Run>  $runs
     * @return array{value: float|null, succeeded: int, total: int, trend: array<string, mixed>|null}
     */
    private function successRate(Collection $runs, CarbonImmutable $today): array
    {
        $window = fn (int $from, int $to) => $runs->filter(fn (array $run): bool => $run['day'] >= $today->subDays($from)->toDateString() && $run['day'] <= $today->subDays($to)->toDateString());
        $rate = function ($set): ?float {
            $total = $set->count();

            return $total === 0 ? null : round($set->where('status', 'success')->count() / $total * 100, 1);
        };
        $current = $window(6, 0);
        $previous = $rate($window(13, 7));
        $value = $rate($current);
        $change = $value === null || $previous === null ? null : round($value - $previous, 1);

        return [
            'value' => $value,
            'succeeded' => $current->where('status', 'success')->count(),
            'total' => $current->count(),
            'trend' => $change === null ? null : [
                'value' => abs($change).' pts',
                'direction' => $change == 0 ? 'flat' : ($change > 0 ? 'up' : 'down'),
                'upIsGood' => true,
            ],
        ];
    }

    private function nextRun(CarbonImmutable $today): CarbonImmutable
    {
        $time = (string) $this->settings->get('core.automation_time', config('core.automation_time', '03:00'));
        [$hour, $minute] = array_map('intval', explode(':', $time) + [1 => 0]);
        $next = $today->setTime($hour, $minute);

        return $next->lte(now()) ? $next->addDay() : $next;
    }

    /**
     * Average pipeline duration per day (sum of its tasks) over the last 7 days, against the 7 days before.
     *
     * @param  Collection<int, Run>  $runs
     * @return array{averageMs: int|null, trend: array<string, mixed>|null, sparkline: list<int>}
     */
    private function duration(Collection $runs, CarbonImmutable $today): array
    {
        $byDay = $runs->groupBy('day')->map(fn ($day): int => (int) $day->sum('duration_ms'));
        $average = function (int $from, int $to) use ($byDay, $today): ?int {
            $days = $byDay->filter(fn (int $ms, string $day): bool => $day >= $today->subDays($from)->toDateString() && $day <= $today->subDays($to)->toDateString());

            return $days->isEmpty() ? null : (int) round($days->avg());
        };
        $current = $average(6, 0);
        $previous = $average(13, 7);
        $sparkline = [];

        for ($offset = 13; $offset >= 0; $offset--) {
            $sparkline[] = $byDay->get($today->subDays($offset)->toDateString(), 0);
        }

        return [
            'averageMs' => $current,
            'trend' => $current === null || $previous === null ? null : Trend::percent($current, $previous, upIsGood: false),
            'sparkline' => $sparkline,
        ];
    }

    /**
     * Runs per day: succeeded, failed, and registered tasks with no run that day (the silent-cron signal). Before
     * today's scheduled time, today's missing runs are not due yet and count as zero.
     *
     * @param  Collection<int, Run>  $runs
     * @return list<array{x: string, success: int, failed: int, missing: int}>
     */
    private function perDay(Collection $runs, CarbonImmutable $since, int $taskCount, bool $todayPending): array
    {
        $byDay = $runs->groupBy('day');
        $days = [];

        for ($offset = 0; $offset < self::DAYS; $offset++) {
            $day = $since->addDays($offset)->toDateString();
            $set = $byDay->get($day, collect());
            $success = $set->where('status', 'success')->count();
            $failed = $set->where('status', 'failed')->count();
            $notDue = $todayPending && $offset === self::DAYS - 1;
            $days[] = ['x' => $day, 'success' => $success, 'failed' => $failed, 'missing' => $notDue ? 0 : max(0, $taskCount - $success - $failed)];
        }

        return $days;
    }

    /**
     * Items processed today per stage, from the counts tasks report in their summaries ("12", "3 invoices").
     *
     * @param  Collection<int, Run>  $runs
     * @param  array<string, array{stage: int, handler: \Closure}>  $tasks
     * @return list<array{stage: int, count: int, tasks: int}>
     */
    private function processedToday(Collection $runs, CarbonImmutable $today, array $tasks): array
    {
        $stages = [];

        foreach ($runs->where('day', $today->toDateString())->where('status', 'success') as $run) {
            $stage = $tasks[$run['task']]['stage'] ?? null;

            if ($stage === null) {
                continue;
            }

            $stages[$stage] ??= ['stage' => $stage, 'count' => 0, 'tasks' => 0];
            $stages[$stage]['tasks']++;
            $stages[$stage]['count'] += preg_match('/^\s*(\d+)/', (string) $run['summary'], $match) === 1 ? (int) $match[1] : 0;
        }

        ksort($stages);

        return array_values($stages);
    }

    /**
     * Average time each stage takes per day over the last 7 days.
     *
     * @param  Collection<int, Run>  $runs
     * @param  array<string, array{stage: int, handler: \Closure}>  $tasks
     * @return list<array{stage: int, averageMs: int}>
     */
    private function stageDurations(Collection $runs, CarbonImmutable $today, array $tasks): array
    {
        $from = $today->subDays(6)->toDateString();
        $totals = [];

        foreach ($runs->filter(fn (array $run): bool => $run['day'] >= $from) as $run) {
            $stage = $tasks[$run['task']]['stage'] ?? null;

            if ($stage !== null) {
                $totals[$stage][$run['day']] = ($totals[$stage][$run['day']] ?? 0) + $run['duration_ms'];
            }
        }

        ksort($totals);

        return array_map(fn (int $stage, array $days): array => ['stage' => $stage, 'averageMs' => (int) round(array_sum($days) / count($days))], array_keys($totals), $totals);
    }
}
