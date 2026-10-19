<?php

namespace App\Modules\Core\Support;

use App\Events\Core\AutomationRunCompleted;
use App\Modules\Core\Models\AutomationRun;
use App\Support\Automation\Automation;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs the daily automation pipeline under one shared cache lock, so a scheduled run, a manual request and a
 * console run never overlap. A run can last longer than any queue's retry_after, so it never runs on a queue
 * worker: the admin "Run now" button stores a request that the scheduler starts within a minute (ADR 0017 §3).
 */
class AutomationRunner
{
    public const LOCK = 'automation:run';

    /** Longest expected run; the lock expires after it if the process dies. */
    public const LOCK_SECONDS = 3600;

    private const REQUEST = 'automation:requested';

    public function __construct(private Automation $automation) {}

    /**
     * Run the pipeline (or one task) for a date. Returns whether any task failed, or null when another run holds
     * the lock and nothing ran.
     *
     * @param  (Closure(string, string): void)|null  $report  receives each task key and its result line
     */
    public function run(CarbonImmutable $date, ?string $only = null, bool $force = false, ?Closure $report = null): ?bool
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->pipeline($date, $only, $force, $report ?? fn (): null => null);
        } finally {
            $lock->release();
        }
    }

    /**
     * Ask for a run from the admin. A newer request replaces an older one that has not started.
     */
    public function request(?string $task, bool $force): void
    {
        Cache::forever(self::REQUEST, ['task' => $task, 'force' => $force]);
    }

    /**
     * @return array{task: string|null, force: bool}|null
     */
    public function pendingRequest(): ?array
    {
        $request = Cache::get(self::REQUEST);

        return is_array($request) ? ['task' => $request['task'] ?? null, 'force' => (bool) ($request['force'] ?? false)] : null;
    }

    /**
     * Start the pending request, if any. While another run holds the lock the request stays pending for the next
     * minute; it is removed only once this process holds the lock, so it runs exactly once.
     *
     * @param  (Closure(string, string): void)|null  $report
     * @return bool|null whether a task failed, or null when nothing ran
     */
    public function runRequested(?Closure $report = null): ?bool
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if ($this->pendingRequest() === null || ! $lock->get()) {
            return null;
        }

        try {
            // Read again under the lock: a newer request may have replaced the one seen above.
            $request = $this->pendingRequest();
            Cache::forget(self::REQUEST);

            return $request === null ? null : $this->pipeline(CarbonImmutable::today(), $request['task'], $request['force'], $report ?? fn (): null => null);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  Closure(string, string): void  $report
     */
    private function pipeline(CarbonImmutable $date, ?string $only, bool $force, Closure $report): bool
    {
        $failed = false;

        foreach ($this->automation->ordered() as $key => $task) {
            if ($only !== null && $only !== $key) {
                continue;
            }

            $run = AutomationRun::query()->whereDate('run_date', $date)->where('task', $key)->first()
                ?? new AutomationRun(['run_date' => $date, 'task' => $key]);

            if ($run->exists && $run->status === 'success' && ! $force) {
                $report($key, '<fg=gray>already done</>');

                continue;
            }

            $started = hrtime(true);

            try {
                $summary = ($task['handler'])($date);
                $run->fill(['status' => 'success', 'summary' => is_scalar($summary) ? (string) $summary : null]);
                $report($key, '<fg=green>'.($run->summary ?? 'done').'</>');
            } catch (Throwable $exception) {
                report($exception);
                $failed = true;
                $run->fill(['status' => 'failed', 'summary' => $exception->getMessage()]);
                $report($key, '<fg=red>failed: '.$exception->getMessage().'</>');
            }

            $run->duration_ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $run->save();
        }

        AutomationRunCompleted::dispatch($date->toDateString(), $failed);

        return $failed;
    }
}
