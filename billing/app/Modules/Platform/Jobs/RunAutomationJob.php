<?php

namespace App\Modules\Platform\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/**
 * "Run now" from the admin automation page. Runs `automation:run` on a worker; the command holds the
 * same `automation:run` cache lock as the scheduled run, so the two never overlap.
 */
class RunAutomationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public ?string $task = null, public bool $force = false) {}

    public function handle(): void
    {
        Artisan::call('automation:run', array_filter([
            '--only' => $this->task,
            '--force' => $this->force,
        ]));
    }
}
