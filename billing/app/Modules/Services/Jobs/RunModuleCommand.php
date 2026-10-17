<?php

namespace App\Modules\Services\Jobs;

use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Support\CommandOutcome;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued driver command for one service (queue "provisioning").
 *
 * Unique per (service, command, attempt) so automation re-runs don't stack duplicates; commands of
 * one service are serialized by the lock in ExecuteModuleCommand (a busy service releases the job
 * back to the queue). Retryable failures schedule the next attempt with the configured backoff;
 * the payload is encrypted because change_password carries the new password.
 */
class RunModuleCommand implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Releases while waiting for the per-service lock count as tries; driver retries are re-dispatches. */
    public int $tries = 20;

    public int $uniqueFor = 3600;

    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(
        public int $serviceId,
        public string $command,
        public array $args = [],
        public string $initiator = 'system',
        public int $attempt = 1,
    ) {
        $this->onQueue('provisioning');
    }

    public function uniqueId(): string
    {
        return "{$this->serviceId}:{$this->command}:{$this->attempt}";
    }

    public function handle(ExecuteModuleCommand $execute): void
    {
        try {
            $outcome = $execute->handle($this->serviceId, ModuleCommandName::from($this->command), $this->args, $this->initiator, $this->attempt);
        } catch (LockTimeoutException) {
            $this->release(15);

            return;
        }

        if ($outcome->status === CommandOutcome::RETRY) {
            self::dispatch($this->serviceId, $this->command, $this->args, $this->initiator, $this->attempt + 1)
                ->delay($outcome->retryDelay);
        }
    }
}
