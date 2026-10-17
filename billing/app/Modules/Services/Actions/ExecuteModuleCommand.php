<?php

namespace App\Modules\Services\Actions;

use App\Events\Services\ProvisioningFailed;
use App\Modules\Services\Drivers\Provisioning\DriverRegistry;
use App\Modules\Services\Drivers\Provisioning\DriverResult;
use App\Modules\Services\Drivers\Provisioning\ProvisioningDriver;
use App\Modules\Services\Drivers\Provisioning\ServiceContext;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\QueueStatus;
use App\Modules\Services\Exceptions\NoServerCapacity;
use App\Modules\Services\Exceptions\RetryableProvisioningException;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\CommandEffects;
use App\Modules\Services\Support\Catalog;
use App\Modules\Services\Support\CommandOutcome;
use App\Modules\Services\Support\Redactor;
use App\Modules\Services\Support\ServerSelector;
use App\Modules\Services\Support\ServiceSettings;
use App\Modules\Services\Support\UsernameGenerator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one attempt of a driver command for a service: serialized per service with a lock,
 * logged (redacted) in module_commands, applied locally on success, and recorded in the
 * module queue + ProvisioningFailed event after the final failed attempt.
 */
class ExecuteModuleCommand
{
    public function __construct(
        private DriverRegistry $drivers,
        private ServerSelector $servers,
        private UsernameGenerator $usernames,
        private Redactor $redactor,
        private ServiceSettings $settings,
        private Catalog $catalog,
        private CommandEffects $effects,
    ) {}

    public static function lockKey(int $serviceId): string
    {
        return "services:module:service:{$serviceId}";
    }

    /**
     * @param  array<string, mixed>  $args
     *
     * @throws LockTimeoutException when another command for the service holds the lock too long
     */
    public function handle(int $serviceId, ModuleCommandName $command, array $args = [], string $initiator = 'system', int $attempt = 1): CommandOutcome
    {
        return Cache::lock(self::lockKey($serviceId), 300)
            ->block($this->settings->lockWaitSeconds(), fn (): CommandOutcome => $this->run($serviceId, $command, $args, $initiator, $attempt));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function run(int $serviceId, ModuleCommandName $command, array $args, string $initiator, int $attempt): CommandOutcome
    {
        $service = Service::withTrashed()->with('client')->find($serviceId);

        if ($service === null) {
            return CommandOutcome::skipped('Service not found.');
        }

        $target = CommandEffects::targetStatus($command, $args);

        if ($target !== null && ! $service->status->canTransitionTo($target)) {
            return CommandOutcome::skipped("A {$service->status->value} service cannot become {$target->value}.");
        }

        $product = $this->catalog->product($service->product_id);
        $driverKey = $product->provisioningDriver ?? $service->server->driver ?? 'none';
        $started = hrtime(true);

        try {
            $driver = $this->drivers->get($driverKey);
            $server = $this->prepare($service, $command, $driver, $product?->serverGroupId, $driverKey);
            $context = new ServiceContext(
                $service,
                $server,
                ['id' => $service->client->id, 'name' => $service->client->display_name, 'email' => $service->client->email],
                $product->driverConfig ?? [],
                $service->config_options ?? [],
                $service->custom_fields ?? [],
            );
            $result = $this->call($driver, $context, $command, $args);
        } catch (RetryableProvisioningException $exception) {
            $result = DriverResult::fail($exception->getMessage(), retryable: true);
        } catch (NoServerCapacity $exception) {
            $result = DriverResult::fail($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $result = DriverResult::fail($exception->getMessage());
        }

        $secrets = [...(isset($context) ? $context->secrets() : []), ...(isset($args['password']) ? [(string) $args['password']] : [])];

        ModuleCommand::query()->create([
            'service_id' => $service->id,
            'server_id' => $service->server_id,
            'driver' => $driverKey,
            'command' => $command->value,
            'initiator' => $initiator,
            'attempt' => $attempt,
            'success' => $result->success,
            'error' => $result->success ? null : $this->redactor->maskValues((string) $result->message, $secrets),
            'request' => $this->redactor->redact($result->request, $secrets) ?: null,
            'response' => $this->redactor->redact($result->response, $secrets) ?: null,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        if ($result->success) {
            return $this->succeeded($service->id, $command, $args, $result);
        }

        $error = $this->redactor->maskValues((string) $result->message, $secrets);

        if ($result->retryable && $attempt < $this->settings->maxAttempts()) {
            return CommandOutcome::retry($error, $this->settings->backoffBefore($attempt + 1));
        }

        return $this->failed($service, $command, $args, $error, $attempt);
    }

    /**
     * Assign server, username and password before "create" so retries reuse them.
     */
    private function prepare(Service $service, ModuleCommandName $command, ProvisioningDriver $driver, ?int $serverGroupId, string $driverKey): ?Server
    {
        if ($command !== ModuleCommandName::Create) {
            return $service->server;
        }

        if ($service->server_id === null && $driver->metadata()->requiresServer) {
            $service->server()->associate($this->servers->select($serverGroupId, $driverKey));
        }

        $service->username ??= $this->usernames->generate($service->domain, $service->server_id, $service->id);
        $service->password ??= $this->usernames->password();
        $service->save();

        return $service->server;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function call(ProvisioningDriver $driver, ServiceContext $context, ModuleCommandName $command, array $args): DriverResult
    {
        return match ($command) {
            ModuleCommandName::Create => $driver->create($context),
            ModuleCommandName::Suspend => $driver->suspend($context, (string) ($args['reason'] ?? 'staff')),
            ModuleCommandName::Unsuspend => $driver->unsuspend($context),
            ModuleCommandName::Terminate => $driver->terminate($context),
            ModuleCommandName::ChangePackage => $driver->changePackage($context, (array) ($args['previous'] ?? [])),
            ModuleCommandName::ChangePassword => $driver->changePassword($context, (string) ($args['password'] ?? '')),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function succeeded(int $serviceId, ModuleCommandName $command, array $args, DriverResult $result): CommandOutcome
    {
        $events = DB::transaction(function () use ($serviceId, $command, $args, $result): array {
            /** @var Service $service */
            $service = Service::withTrashed()->lockForUpdate()->findOrFail($serviceId);
            $events = $this->effects->apply($service, $command, $args, $result);

            ModuleQueueItem::query()
                ->where('subject_type', ModuleQueueItem::SUBJECT_SERVICE)
                ->where('subject_id', $serviceId)
                ->where('command', $command->value)
                ->where('status', QueueStatus::Open->value)
                ->update(['status' => QueueStatus::Resolved->value]);

            return $events;
        });

        foreach ($events as $event) {
            event($event);
        }

        return CommandOutcome::succeeded($result->message);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function failed(Service $service, ModuleCommandName $command, array $args, string $error, int $attempt): CommandOutcome
    {
        DB::transaction(function () use ($service, $command, $args, $error, $attempt): void {
            $item = ModuleQueueItem::query()
                ->where('subject_type', ModuleQueueItem::SUBJECT_SERVICE)
                ->where('subject_id', $service->id)
                ->where('command', $command->value)
                ->where('status', QueueStatus::Open->value)
                ->first() ?? new ModuleQueueItem([
                    'subject_type' => ModuleQueueItem::SUBJECT_SERVICE,
                    'subject_id' => $service->id,
                    'command' => $command->value,
                    'status' => QueueStatus::Open,
                ]);

            $item->fill(['args' => $args, 'last_error' => $error, 'attempts' => $item->attempts + $attempt])->save();
            $this->effects->failed($service, $command, $args, $error);
        });

        ProvisioningFailed::dispatch($service->id, $service->client_id, $command->value, $error);

        return CommandOutcome::failed($error);
    }
}
