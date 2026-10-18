<?php

namespace App\Modules\Services\Services;

use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Jobs\RunModuleCommand;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\CommandOutcome;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Entry point for running driver commands: validates the transition up front, then runs the
 * command now (staff/registry, with queued retries) or queues it (automation, listeners).
 */
class ModuleCommandDispatcher
{
    public function __construct(private ExecuteModuleCommand $execute) {}

    /**
     * @param  array<string, mixed>  $args
     */
    public function queue(Service $service, ModuleCommandName $command, array $args = [], string $initiator = 'system'): void
    {
        $this->assertAllowed($service, $command, $args);

        RunModuleCommand::dispatch($service->id, $command->value, $args, $initiator)->afterCommit();
    }

    /**
     * Run the first attempt synchronously so staff see the result; retries continue on the queue.
     *
     * @param  array<string, mixed>  $args
     */
    public function runNow(Service $service, ModuleCommandName $command, array $args = [], string $initiator = 'system'): CommandOutcome
    {
        $this->assertAllowed($service, $command, $args);

        try {
            $outcome = $this->execute->handle($service->id, $command, $args, $initiator);
        } catch (LockTimeoutException) {
            RunModuleCommand::dispatch($service->id, $command->value, $args, $initiator);

            return CommandOutcome::queued('Another action is running for this service; this one was queued.');
        }

        if ($outcome->status === CommandOutcome::RETRY) {
            RunModuleCommand::dispatch($service->id, $command->value, $args, $initiator, 2)->delay($outcome->retryDelay)->afterCommit();
        }

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function assertAllowed(Service $service, ModuleCommandName $command, array $args): void
    {
        $target = CommandEffects::targetStatus($command, $args);

        if ($target !== null) {
            $service->status->assertCanTransitionTo($target);
        }
    }
}
