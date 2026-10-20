<?php

namespace App\Modules\Platform\Jobs;

use App\Modules\Core\Console\Commands\RunAutomation;
use App\Modules\Core\Support\ActivityLogger;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * "Run now" from the admin automation page. Runs `automation:run` on the automation connection, whose retry_after is
 * above this job's timeout, so a long run is never handed to a second worker while the first still runs it (#82).
 *
 * Two locks keep runs apart: this job is unique, so a second "Run now" is not queued while one waits or runs, and
 * the command holds the `automation:run` lock that the scheduled run also takes.
 */
class RunAutomationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = Queues::AUTOMATION_TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    /**
     * The unique lock expires with the reservation, if a worker dies without releasing it. It counts from the click,
     * so a job that waited in the queue can outlive it; the command lock still keeps runs apart then.
     */
    public int $uniqueFor = Queues::AUTOMATION_RETRY_AFTER_SECONDS;

    public function __construct(public ?string $task = null, public bool $force = false)
    {
        $this->onConnection(Queues::AUTOMATION)->onQueue(Queues::AUTOMATION);
    }

    public function handle(ActivityLogger $activity): void
    {
        // A worker on another connection reads this queue with that connection's retry_after (240 s by default) and
        // would hand a long run to a second worker (ADR 0014). Fail before running anything.
        $connection = $this->job?->getConnectionName();

        if ($this->job !== null && $connection !== Queues::AUTOMATION) {
            $this->fail(new RuntimeException("Automation run not started: a worker on the [{$connection}] connection took it. Run these jobs with `php artisan queue:work ".Queues::AUTOMATION.' --queue='.Queues::AUTOMATION.'` (install.md §6).'));

            return;
        }

        // The scheduled or a console run holds the command lock: record the skip instead of a silent no-op.
        if (RunAutomation::inProgress()) {
            $activity->log('Automation run skipped: another run was in progress', properties: [
                'task' => $this->task,
                'force' => $this->force,
            ], event: 'automation.skipped');

            return;
        }

        Artisan::call('automation:run', array_filter([
            '--only' => $this->task,
            '--force' => $this->force,
        ]));
    }
}
