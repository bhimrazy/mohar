<?php

namespace App\Modules\Core\Console\Commands;

use App\Contracts\Core\CompanyClock;
use App\Events\Core\AutomationRunCompleted;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Support\AutomationLogRetention;
use App\Support\Automation\Automation;
use App\Support\CalendarDate;
use App\Support\Integrations\Redactor;
use App\Support\Queues;
use Illuminate\Cache\Lock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use LogicException;
use Throwable;

#[Signature('automation:run {--date= : Run date (Y-m-d), defaults to today on the company clock} {--only= : Run a single task key} {--force : Re-run tasks that already succeeded for the date}')]
#[Description('Run the daily billing automation pipeline in stage order (idempotent per task and date)')]
class RunAutomation extends Command
{
    /** Cache lock shared by the scheduled run and manual "run now" requests. */
    public const LOCK = 'automation:run';

    /**
     * Whether a run (scheduled, console or "Run now") holds the shared lock right now. It only reads the lock: taking
     * it, even briefly, could make a scheduled run starting at that instant skip the day (#132).
     */
    public static function inProgress(): bool
    {
        $lock = Cache::lock(self::LOCK);

        // Every framework store's lock extends Illuminate\Cache\Lock; the contract has no read-only check.
        if (! $lock instanceof Lock) {
            throw new LogicException('The cache store\'s lock cannot be read without taking it: '.$lock::class);
        }

        return $lock->isLocked();
    }

    public function handle(Automation $automation, Redactor $redactor, CompanyClock $clock): int
    {
        $lock = Cache::lock(self::LOCK, Queues::AUTOMATION_TIMEOUT_SECONDS);

        if (! $lock->get()) {
            $this->components->warn('Another automation run is in progress.');

            return self::FAILURE;
        }

        try {
            return $this->runPipeline($automation, $redactor, $clock);
        } finally {
            $lock->release();
        }
    }

    private function runPipeline(Automation $automation, Redactor $redactor, CompanyClock $clock): int
    {
        try {
            $date = $this->option('date') ? CalendarDate::parse((string) $this->option('date')) : $clock->calendarDate();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $retention = app(AutomationLogRetention::class);

        // Older run rows are pruned, so such a date would run every task again (ADR 0013 Amendment (#460)).
        if (! $retention->mayRun($date, $clock->calendarDate())) {
            $pruned = $retention->prunedThrough();
            $this->components->error('Run dates before '.$retention->oldestRunDate($clock->calendarDate()).($pruned === null ? '' : ", or on or before {$pruned},").' are past the automation log retention and cannot be run.');

            return self::FAILURE;
        }

        $failed = false;

        foreach (array_keys($automation->ordered()) as $key) {
            if ($this->option('only') && $this->option('only') !== $key) {
                continue;
            }

            $run = AutomationRun::query()->whereDate('run_date', $date->toString())->where('task', $key)->first()
                ?? new AutomationRun(['run_date' => $date->toString(), 'task' => $key]);

            if ($run->exists && $run->status === 'success' && ! $this->option('force')) {
                $this->components->twoColumnDetail($key, '<fg=gray>already done</>');

                continue;
            }

            $started = hrtime(true);

            try {
                $summary = $automation->run($key, $date);
                $run->fill(['status' => 'success', 'summary' => is_scalar($summary) ? (string) $summary : null]);
                $this->components->twoColumnDetail($key, '<fg=green>'.($run->summary ?? 'done').'</>');
            } catch (Throwable $exception) {
                report($exception);
                $failed = true;
                $message = $redactor->maskValues($exception->getMessage(), []);
                $run->fill(['status' => 'failed', 'summary' => $message]);
                $this->components->twoColumnDetail($key, '<fg=red>failed: '.$message.'</>');
            }

            $run->duration_ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $run->save();
        }

        AutomationRunCompleted::dispatch($date->toString(), $failed);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
