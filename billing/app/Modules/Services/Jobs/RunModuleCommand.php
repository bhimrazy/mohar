<?php

namespace App\Modules\Services\Jobs;

use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Services\ServiceDrivers;
use App\Modules\Services\Support\CommandOutcome;
use App\Support\Queues;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queued driver command for one service (queue "provisioning").
 *
 * - Exactly one try per job: driver retries and lock waits are explicit re-dispatches (new attempt /
 *   lock-retry numbers), so a redelivered job after a worker crash never calls the driver again —
 *   it fails and failed() records a "verify outcome" item instead.
 * - timeout (the driver's command timeout within services.module.max_job_timeout_seconds) < per-service lock TTL
 *   < queue retry_after; a timeout fails the job (failOnTimeout).
 * - Unique per (service, command, attempt, lock retry) so automation re-runs don't stack duplicates.
 * - The payload is encrypted because change_password carries the new password.
 */
class RunModuleCommand implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

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
        public int $lockRetries = 0,
        ?int $timeoutSeconds = null,
    ) {
        $this->onQueue(Queues::PROVISIONING);
        $this->timeout = $timeoutSeconds ?? app(ServiceDrivers::class)->jobTimeoutFor($serviceId);
    }

    public function uniqueId(): string
    {
        return "{$this->serviceId}:{$this->command}:{$this->attempt}:{$this->lockRetries}";
    }

    public function handle(ExecuteModuleCommand $execute): void
    {
        try {
            $outcome = $execute->handle($this->serviceId, ModuleCommandName::from($this->command), $this->args, $this->initiator, $this->attempt, jobTimeout: $this->timeout);
        } catch (LockTimeoutException) {
            if ($this->lockRetries >= (int) config('services.module.lock_retries', 20)) {
                $execute->abandon($this->serviceId, ModuleCommandName::from($this->command), $this->args, 'Another action kept this service busy; the command was not run.', jobDied: false);

                return;
            }

            self::dispatch($this->serviceId, $this->command, $this->args, $this->initiator, $this->attempt, $this->lockRetries + 1, timeoutSeconds: $this->timeout)->delay(15);

            return;
        }

        if ($outcome->status === CommandOutcome::RETRY) {
            self::dispatch($this->serviceId, $this->command, $this->args, $this->initiator, $this->attempt + 1, timeoutSeconds: $this->timeout)
                ->delay($outcome->retryDelay);
        }
    }

    /**
     * Timeout, worker crash (redelivery beyond the single try) or an unexpected exception.
     */
    public function failed(?Throwable $exception): void
    {
        app(ExecuteModuleCommand::class)->abandon(
            $this->serviceId,
            ModuleCommandName::from($this->command),
            $this->args,
            $exception !== null ? class_basename($exception).': the job did not finish' : 'The job did not finish',
        );
    }
}
