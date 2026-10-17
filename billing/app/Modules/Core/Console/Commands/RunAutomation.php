<?php

namespace App\Modules\Core\Console\Commands;

use App\Modules\Core\Models\AutomationRun;
use App\Support\Automation\Automation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('automation:run {--date= : Run date (Y-m-d), defaults to today} {--only= : Run a single task key} {--force : Re-run tasks that already succeeded for the date}')]
#[Description('Run the daily billing automation pipeline in stage order (idempotent per task and date)')]
class RunAutomation extends Command
{
    public function handle(Automation $automation): int
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

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
