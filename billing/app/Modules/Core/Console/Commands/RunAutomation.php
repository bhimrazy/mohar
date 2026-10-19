<?php

namespace App\Modules\Core\Console\Commands;

use App\Modules\Core\Support\AutomationRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('automation:run {--date= : Run date (Y-m-d), defaults to today} {--only= : Run a single task key} {--force : Re-run tasks that already succeeded for the date}')]
#[Description('Run the daily billing automation pipeline in stage order (idempotent per task and date)')]
class RunAutomation extends Command
{
    public function handle(AutomationRunner $runner): int
    {
        $date = $this->option('date') ? CarbonImmutable::parse((string) $this->option('date'))->startOfDay() : CarbonImmutable::today();
        $only = $this->option('only') ? (string) $this->option('only') : null;

        $failed = $runner->run($date, $only, (bool) $this->option('force'), function (string $key, string $result): void {
            $this->components->twoColumnDetail($key, $result);
        });

        if ($failed === null) {
            $this->components->warn('Another automation run is in progress.');

            return self::FAILURE;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
