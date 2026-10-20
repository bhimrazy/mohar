<?php

namespace App\Modules\Platform\Jobs;

use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

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

    /** The unique lock expires with the reservation, if a worker dies without releasing it. */
    public int $uniqueFor = Queues::AUTOMATION_RETRY_AFTER_SECONDS;

    public function __construct(public ?string $task = null, public bool $force = false)
    {
        $this->onConnection(Queues::AUTOMATION)->onQueue(Queues::AUTOMATION);
    }

    public function handle(): void
    {
        Artisan::call('automation:run', array_filter([
            '--only' => $this->task,
            '--force' => $this->force,
        ]));
    }
}
