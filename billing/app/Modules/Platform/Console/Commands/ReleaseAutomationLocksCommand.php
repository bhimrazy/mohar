<?php

namespace App\Modules\Platform\Console\Commands;

use App\Modules\Core\Console\Commands\RunAutomation;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Jobs\RunAutomationJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * A worker killed mid-run (out of memory, a stop timeout that ran out) cannot release its locks: "Run now" is then
 * refused until the unique lock expires, about 65 minutes after the click (install.md §6). This releases both locks.
 */
#[Signature('automation:release-locks {--force : Release without asking}')]
#[Description('Release the automation locks a stopped worker left behind; only while no automation run is running')]
class ReleaseAutomationLocksCommand extends Command
{
    public function handle(ActivityLogger $activity): int
    {
        if (! $this->option('force') && ! $this->confirm('Release the automation locks? Do this only when no automation worker or scheduled run is running: releasing them lets a second run start alongside one.')) {
            $this->components->warn('Nothing released.');

            return self::FAILURE;
        }

        Cache::lock(UniqueLock::getKey(new RunAutomationJob))->forceRelease();
        Cache::lock(RunAutomation::LOCK)->forceRelease();

        $activity->log('Automation locks released', event: 'automation.locks_released');
        $this->components->info('Automation locks released. Run now can start a run again.');

        return self::SUCCESS;
    }
}
