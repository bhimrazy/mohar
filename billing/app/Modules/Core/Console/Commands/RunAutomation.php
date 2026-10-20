<?php

namespace App\Modules\Core\Console\Commands;

use App\Events\Core\AutomationRunCompleted;
use App\Modules\Core\Models\AutomationRun;
use App\Support\Automation\Automation;
use App\Support\Queues;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('automation:run {--date= : Run date (Y-m-d), defaults to today} {--only= : Run a single task key} {--force : Re-run tasks that already succeeded for the date}')]
#[Description('Run the daily billing automation pipeline in stage order (idempotent per task and date)')]
class RunAutomation extends Command
{
    /** Cache lock shared by the scheduled run and manual "run now" requests. */
    public const LOCK = 'automation:run';

    /**
     * Whether a run (scheduled, console or "Run now") holds the shared lock right now.
     */
    public static function inProgress(): bool
    {
        $lock = Cache::lock(self::LOCK, 1);

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }

    public function handle(Automation $automation): int
    {
        $lock = Cache::lock(self::LOCK, Queues::AUTOMATION_TIMEOUT_SECONDS);

        if (! $lock->get()) {
            $this->components->warn('Another automation run is in progress.');

            return self::FAILURE;
        }

        try {
            return $this->runPipeline($automation);
        } finally {
            $lock->release();
        }
    }

    private function runPipeline(Automation $automation): int
    {
        $date = $this->option('date') ? CarbonImmutable::parse((string) $this->option('date'))->startOfDay() : CarbonImmutable::today();
        $failed = false;

        foreach ($automation->ordered() as $key => $task) {
            if ($this->option('only') && $this->option('only') !== $key) {
                continue;
            }

            $run = AutomationRun::query()->whereDate('run_date', $date)->where('task', $key)->first()
                ?? new AutomationRun(['run_date' => $date, 'task' => $key]);

            if ($run->exists && $run->status === 'success' && ! $this->option('force')) {
                $this->components->twoColumnDetail($key, '<fg=gray>already done</>');

                continue;
            }

            $started = hrtime(true);

            try {
                $summary = ($task['handler'])($date);
                $run->fill(['status' => 'success', 'summary' => is_scalar($summary) ? (string) $summary : null]);
                $this->components->twoColumnDetail($key, '<fg=green>'.($run->summary ?? 'done').'</>');
            } catch (Throwable $exception) {
                report($exception);
                $failed = true;
                $run->fill(['status' => 'failed', 'summary' => $exception->getMessage()]);
                $this->components->twoColumnDetail($key, '<fg=red>failed: '.$exception->getMessage().'</>');
            }

            $run->duration_ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $run->save();
        }

        AutomationRunCompleted::dispatch($date->toDateString(), $failed);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
