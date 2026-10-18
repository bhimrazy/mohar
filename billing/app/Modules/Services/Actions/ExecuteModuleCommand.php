<?php

namespace App\Modules\Services\Actions;

use App\Contracts\Services\Provisioning\ChangesPackage;
use App\Contracts\Services\Provisioning\ChangesPassword;
use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Data\Services\Provisioning\UsernamePolicy;
use App\Enums\DriverOutcome;
use App\Events\Services\ProvisioningFailed;
use App\Events\Services\ServerCapacityExhausted;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\QueueStatus;
use App\Modules\Services\Exceptions\NoServerCapacity;
use App\Modules\Services\Models\CancellationRequest;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\CommandEffects;
use App\Modules\Services\Services\ProvisioningSnapshots;
use App\Modules\Services\Services\ServiceDrivers;
use App\Modules\Services\Support\Catalog;
use App\Modules\Services\Support\CommandOutcome;
use App\Modules\Services\Support\ServerSelector;
use App\Modules\Services\Support\ServiceSettings;
use App\Modules\Services\Support\UsernameGenerator;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use App\Support\Services\RetryableProvisioningException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one attempt of a driver command for a service.
 *
 * - Serialized per service with a cache lock (also taken by staff status changes).
 * - A `running` module_commands row is written before the driver is called; if a later pickup finds
 *   an unfinished `create`, the remote outcome is unknown and the command is NOT re-run: a "verify"
 *   module-queue item is opened for staff instead (no double provisioning).
 * - The new status is written only if the transition is still legal on the locked row; otherwise a
 *   "reconcile" item is opened and local state is left alone.
 * - A driver answer of unknown outcome (DriverResult::unknown()) is never retried: the attempt is logged as unknown
 *   and a "verify" item is opened, which also blocks any further create until staff settle it.
 * - Drivers receive a ProvisioningContext snapshot (ProvisioningSnapshots), never models.
 */
class ExecuteModuleCommand
{
    /** Seconds between the driver's deadline and the job timeout, for logging and the result write. */
    private const DEADLINE_MARGIN_SECONDS = 5;

    /** Usernames a create may move to when the server already holds the assigned one (spec §4.1). */
    private const MAX_RENAMES = 5;

    public function __construct(
        private ProvisioningDriverRegistry $drivers,
        private ServiceDrivers $serviceDrivers,
        private ProvisioningSnapshots $snapshots,
        private ServerSelector $servers,
        private UsernameGenerator $usernames,
        private Redactor $redactor,
        private ServiceSettings $settings,
        private Catalog $catalog,
        private CommandEffects $effects,
        private ActivityLogger $activity,
    ) {}

    public static function lockKey(int $serviceId): string
    {
        return "services:module:service:{$serviceId}";
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  int|null  $jobTimeout  the run time allowed to this attempt (the job's timeout); the lock outlives it
     *
     * @throws LockTimeoutException when another command for the service holds the lock too long
     */
    public function handle(int $serviceId, ModuleCommandName $command, array $args = [], string $initiator = 'system', int $attempt = 1, ?int $jobTimeout = null): CommandOutcome
    {
        $jobTimeout ??= $this->serviceDrivers->jobTimeoutFor($serviceId);
        // One deadline for the whole job, fixed before waiting for the lock: renames re-run the driver within it.
        $deadline = CarbonImmutable::now()->addSeconds(max(5, $jobTimeout - self::DEADLINE_MARGIN_SECONDS));

        return Cache::lock(self::lockKey($serviceId), $this->settings->lockTtlSeconds($jobTimeout))
            ->block($this->settings->lockWaitSeconds(), fn (): CommandOutcome => $this->run($serviceId, $command, $args, $initiator, $attempt, $deadline));
    }

    /**
     * The job died, timed out or ran out of tries: record the failure without calling the driver.
     * Only a job that died mid-call ($jobDied) marks running attempts as unknown; a job that merely
     * waited for the lock must not touch a call another worker is still making.
     *
     * @param  array<string, mixed>  $args
     */
    public function abandon(int $serviceId, ModuleCommandName $command, array $args, string $error, bool $jobDied = true): void
    {
        $service = Service::withTrashed()->find($serviceId);

        if ($service === null) {
            return;
        }

        if ($jobDied && $this->markUnfinishedAsUnknown($service->id, $command) > 0) {
            $this->openQueueItem($service, $command, $args, self::unknownMessage($error), 1, ModuleQueueItem::KIND_VERIFY);
        } else {
            $this->openQueueItem($service, $command, $args, $error, 1, ModuleQueueItem::KIND_FAILURE);
        }

        $this->effects->failed($service, $command, $args, $error);
        ProvisioningFailed::dispatch($service->id, $service->client_id, $command->value, $error);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function run(int $serviceId, ModuleCommandName $command, array $args, string $initiator, int $attempt, CarbonImmutable $deadline, int $renames = 0): CommandOutcome
    {
        $service = Service::withTrashed()->with('client')->find($serviceId);

        if ($service === null) {
            return CommandOutcome::skipped('Service not found.');
        }

        $target = CommandEffects::targetStatus($command, $args);

        if ($target !== null && ! $service->status->canTransitionTo($target)) {
            return CommandOutcome::skipped("A {$service->status->value} service cannot become {$target->value}.");
        }

        if (isset($args['cancellation_request_id']) && ! $this->requestIsOpen((int) $args['cancellation_request_id'])) {
            return CommandOutcome::skipped('The cancellation request is no longer open.');
        }

        if ($command === ModuleCommandName::Create && ! ($args['verified'] ?? false)) {
            if ($this->awaitingVerification($service->id)) {
                return CommandOutcome::skipped('A previous create has an unknown outcome and waits for staff verification.');
            }

            if ($this->markUnfinishedAsUnknown($service->id, $command) > 0) {
                $error = 'A previous create attempt did not finish; its outcome is unknown. Check the server, then retry or resolve.';
                $this->openQueueItem($service, $command, $args, $error, $attempt, ModuleQueueItem::KIND_VERIFY);
                ProvisioningFailed::dispatch($service->id, $service->client_id, $command->value, $error);

                return CommandOutcome::failed($error);
            }
        }

        $product = $this->catalog->product($service->product_id);
        $driverKey = $this->serviceDrivers->keyFor($service);
        $started = hrtime(true);
        $log = null;
        $context = null;
        $driver = null;
        $target = null;

        try {
            $driver = $this->drivers->get($driverKey);
            $server = $this->prepare($service, $command, $driver, $product?->serverGroupId, $driverKey);
            // What a create sends, recorded before the call: only an account matching it may later be adopted.
            $target = $command === ModuleCommandName::Create ? ['username' => $service->username, 'domain' => $service->domain] : null;
            $context = $this->snapshots->context($service, $server, $product, $driver, $deadline, $target === null ? null : $this->unknownAttemptAt($service));
            $log = $this->startLog($service, $driverKey, $command, $initiator, $attempt, $target);
            $result = $this->callDriver($driver, $context, $command, $args);
        } catch (RetryableProvisioningException $exception) {
            $result = DriverResult::fail($exception->getMessage(), retryable: true);
        } catch (NoServerCapacity $exception) {
            ServerCapacityExhausted::dispatch($service->id, $service->product_id, $product?->serverGroupId);
            $result = DriverResult::fail($exception->getMessage());
        } catch (Throwable $exception) {
            $secrets = $context?->secrets() ?? [];
            report(new \RuntimeException($this->redactor->maskValues($exception->getMessage(), $secrets)));
            $result = DriverResult::fail($exception->getMessage());
        }

        $secrets = [...($context?->secrets() ?? []), ...(isset($args['password']) ? [(string) $args['password']] : [])];
        $error = $result->success ? null : $this->redactor->maskValues((string) $result->message, $secrets);
        $outcome = $result->outcome();

        $attributes = [
            'success' => $result->success,
            'status' => match ($outcome) {
                DriverOutcome::Completed => ModuleCommand::SUCCEEDED,
                DriverOutcome::Unknown => ModuleCommand::UNKNOWN,
                default => ModuleCommand::FAILED,
            },
            'error' => $error,
            // The recorded target goes last, so nothing a driver logs can overwrite it.
            'request' => [...$this->redactor->redact($result->request, $secrets), ...($target === null ? [] : ['target' => $target])] ?: null,
            'response' => $this->redactor->redact($result->response, $secrets) ?: null,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ];

        if ($log !== null) {
            $log->update($attributes);
        } else {
            $this->startLog($service, $driverKey, $command, $initiator, $attempt)->update($attributes);
        }

        if ($outcome === DriverOutcome::Completed) {
            return $this->succeeded($service->id, $command, $args, $result);
        }

        if ($command === ModuleCommandName::Create && $outcome === DriverOutcome::Retryable && $renames < self::MAX_RENAMES && $this->takeRename($service, $result, $driver)) {
            return $this->run($serviceId, $command, $args, $initiator, $attempt, $deadline, $renames + 1);
        }

        if ($outcome === DriverOutcome::Retryable && $attempt < $this->settings->maxAttempts()) {
            return CommandOutcome::retry((string) $error, $this->settings->backoffBefore($attempt + 1));
        }

        $kind = $outcome === DriverOutcome::Unknown ? ModuleQueueItem::KIND_VERIFY : ModuleQueueItem::KIND_FAILURE;
        $error = $outcome === DriverOutcome::Unknown ? self::unknownMessage((string) $error) : (string) $error;

        return $this->failed($service, $command, $args, $error, $attempt, $kind);
    }

    /**
     * @param  array{username: ?string, domain: ?string}|null  $target  what a create is about to send
     */
    private function startLog(Service $service, string $driverKey, ModuleCommandName $command, string $initiator, int $attempt, ?array $target = null): ModuleCommand
    {
        return ModuleCommand::query()->create([
            'request' => $target === null ? null : ['target' => $target],
            'service_id' => $service->id,
            'server_id' => $service->server_id,
            'driver' => $driverKey,
            'command' => $command->value,
            'initiator' => $initiator,
            'attempt' => $attempt,
            'success' => false,
            'status' => ModuleCommand::RUNNING,
        ]);
    }

    /**
     * When the latest create with an unknown outcome was sent, if it was sent with the service's current username and
     * domain. A driver may adopt only what that attempt left behind (ProvisioningContext::$unknownAttemptAt).
     */
    private function unknownAttemptAt(Service $service): ?CarbonImmutable
    {
        if ($service->domain === null || $service->domain === '') {
            return null;
        }

        $attempt = ModuleCommand::query()
            ->where('service_id', $service->id)
            ->where('command', ModuleCommandName::Create->value)
            ->where('status', ModuleCommand::UNKNOWN)
            ->orderByDesc('id')
            ->first();

        if ($attempt === null) {
            return null;
        }

        $target = $attempt->request['target'] ?? null;

        return is_array($target) && ($target['username'] ?? null) === $service->username && ($target['domain'] ?? null) === $service->domain
            ? $attempt->created_at
            : null;
    }

    /**
     * A create that found its username taken on the server proposes a free one (DriverResult::renameUsername()). Take
     * it when it fits the driver's policy and no other service on the server has it; the create then runs again.
     */
    private function takeRename(Service $service, DriverResult $result, ?ProvisioningDriver $driver): bool
    {
        $username = $result->data['username'] ?? null;
        $policy = $driver?->metadata()->usernamePolicy ?? new UsernamePolicy;

        if ($driver === null || ! is_string($username) || $username === $service->username || $policy->violation($username) !== null) {
            return false;
        }

        // The proposed name first, then local variants of it; the next attempt checks the chosen one on the server.
        $base = rtrim($username, '0123456789') ?: $username;
        $candidates = [$username];

        for ($suffix = 1; count($candidates) < self::MAX_RENAMES && $suffix <= 99; $suffix++) {
            $candidate = substr($base, 0, $policy->maxLength - strlen((string) $suffix)).$suffix;

            if ($candidate !== $service->username && $policy->violation($candidate) === null && ! in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            if (Service::withTrashed()->where('server_id', $service->server_id)->where('username', $candidate)->whereKeyNot($service->id)->exists()) {
                continue;
            }

            try {
                // The unique (server_id, username) index settles a race with another service. No transaction is open
                // here, so a refused statement aborts nothing else (PostgreSQL rule 7).
                Service::withTrashed()->whereKey($service->id)->update(['username' => $candidate]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            $this->activity->log("Username for {$service->label()} changed: the server already holds the assigned one", $service, $service->client_id, ['from' => $service->username, 'to' => $candidate]);
            $service->username = $candidate;

            return true;
        }

        return false;
    }

    /**
     * Whether an earlier create has an unknown outcome that staff have not verified yet. While this
     * holds, no path (automation, payment replays, the registry) may call the driver's create.
     */
    public function awaitingVerification(int $serviceId): bool
    {
        return ModuleQueueItem::query()
            ->where('subject_type', ModuleQueueItem::SUBJECT_SERVICE)
            ->where('subject_id', $serviceId)
            ->where('command', ModuleCommandName::Create->value)
            ->where('kind', ModuleQueueItem::KIND_VERIFY)
            ->where('status', QueueStatus::Open->value)
            ->exists();
    }

    private function markUnfinishedAsUnknown(int $serviceId, ModuleCommandName $command): int
    {
        return ModuleCommand::query()
            ->where('service_id', $serviceId)
            ->where('command', $command->value)
            ->where('status', ModuleCommand::RUNNING)
            ->update(['status' => ModuleCommand::UNKNOWN, 'error' => 'Worker stopped before the driver answered.']);
    }

    /**
     * Staff checked the server: earlier attempts with an unknown outcome are no longer pending.
     */
    public function acknowledgeUnknown(int $serviceId, ModuleCommandName $command): void
    {
        ModuleCommand::query()
            ->where('service_id', $serviceId)
            ->where('command', $command->value)
            ->where('status', ModuleCommand::UNKNOWN)
            ->update(['status' => ModuleCommand::VERIFIED]);
    }

    private function requestIsOpen(int $requestId): bool
    {
        return CancellationRequest::query()->whereKey($requestId)->where('status', CancellationStatus::Open->value)->exists();
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

        $service->username ??= $this->usernames->generate($service->domain, $service->server_id, $service->id, $driver->metadata()->usernamePolicy);
        $service->password ??= $this->usernames->password();
        $service->save();

        return $service->server;
    }

    private static function unknownMessage(string $error): string
    {
        return 'Outcome unknown: '.rtrim($error, '. ').'. Check the server before retrying.';
    }

    /**
     * Call the driver. Anything it throws other than RetryableProvisioningException happened while the request may
     * already have been sent, so the outcome is unknown: never retried, settled by staff (ADR 0009 §4). Failures
     * before the call (server selection, snapshot building) stay rejected.
     *
     * @param  array<string, mixed>  $args
     *
     * @throws RetryableProvisioningException
     */
    private function callDriver(ProvisioningDriver $driver, ProvisioningContext $context, ModuleCommandName $command, array $args): DriverResult
    {
        try {
            return $this->call($driver, $context, $command, $args);
        } catch (RetryableProvisioningException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $message = $this->redactor->maskValues($exception->getMessage(), $context->secrets());
            report(new \RuntimeException($message));

            return DriverResult::unknown("The {$driver->name()} driver failed during the call: {$message}");
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function call(ProvisioningDriver $driver, ProvisioningContext $context, ModuleCommandName $command, array $args): DriverResult
    {
        return match (true) {
            $command === ModuleCommandName::Create => $driver->create($context),
            $command === ModuleCommandName::Suspend => $driver->suspend($context, $this->suspensionText((string) ($args['reason'] ?? 'staff'))),
            $command === ModuleCommandName::Unsuspend => $driver->unsuspend($context),
            $command === ModuleCommandName::Terminate => $driver->terminate($context),
            $command === ModuleCommandName::ChangePackage && $driver instanceof ChangesPackage => $driver->changePackage($context, $this->snapshots->previousPackage((array) ($args['previous'] ?? []))),
            $command === ModuleCommandName::ChangePassword && $driver instanceof ChangesPassword => $driver->changePassword($context, (string) ($args['password'] ?? '')),
            default => DriverResult::fail(sprintf('The %s driver cannot %s; make the change on the server by hand.', $driver->name(), mb_strtolower($command->label()))),
        };
    }

    /**
     * The readable suspension reason a panel records (ProvisioningDriver::suspend()).
     */
    private function suspensionText(string $reason): string
    {
        return match ($reason) {
            'overdue' => 'Overdue invoice',
            'addon_overdue' => 'Overdue add-on invoice',
            'staff' => 'Suspended by staff',
            default => str_starts_with($reason, 'staff:') ? trim(substr($reason, 6)) : $reason,
        };
    }

    /**
     * Apply the result on the locked row, or open a reconcile item when the service moved meanwhile.
     *
     * @param  array<string, mixed>  $args
     */
    private function succeeded(int $serviceId, ModuleCommandName $command, array $args, DriverResult $result): CommandOutcome
    {
        $outcome = DB::transaction(function () use ($serviceId, $command, $args, $result): array {
            /** @var Service $service */
            $service = Service::withTrashed()->lockForUpdate()->findOrFail($serviceId);
            $target = CommandEffects::targetStatus($command, $args);
            $conflict = match (true) {
                $target !== null && ! $service->status->canTransitionTo($target) => "The service became {$service->status->value} while {$command->label()} ran; the remote account was changed but the local status was not.",
                isset($args['cancellation_request_id']) && ! $this->requestIsOpen((int) $args['cancellation_request_id']) => 'The cancellation request was voided while the account was being terminated; check the server.',
                default => null,
            };

            if ($conflict !== null) {
                $this->openQueueItem($service, $command, $args, $conflict, 1, ModuleQueueItem::KIND_RECONCILE);
                $this->activity->log("{$command->noun()} for {$service->label()} needs reconciling", $service, $service->client_id, ['reason' => $conflict]);

                return ['conflict' => $conflict, 'events' => [], 'client_id' => $service->client_id];
            }

            $events = $this->effects->apply($service, $command, $args, $result);

            ModuleQueueItem::query()
                ->where('subject_type', ModuleQueueItem::SUBJECT_SERVICE)
                ->where('subject_id', $serviceId)
                ->where('command', $command->value)
                ->where('status', QueueStatus::Open->value)
                ->when($command === ModuleCommandName::Create && ! ($args['verified'] ?? false), fn ($query) => $query->where('kind', '!=', ModuleQueueItem::KIND_VERIFY))
                ->update(['status' => QueueStatus::Resolved->value]);

            if ($args['verified'] ?? false) {
                $this->acknowledgeUnknown($serviceId, $command);
            }

            return ['conflict' => null, 'events' => $events, 'client_id' => $service->client_id];
        });

        if ($outcome['conflict'] !== null) {
            ProvisioningFailed::dispatch($serviceId, $outcome['client_id'], $command->value, $outcome['conflict']);

            return CommandOutcome::failed($outcome['conflict']);
        }

        foreach ($outcome['events'] as $event) {
            event($event);
        }

        return CommandOutcome::succeeded($result->message);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function failed(Service $service, ModuleCommandName $command, array $args, string $error, int $attempt, string $kind = ModuleQueueItem::KIND_FAILURE): CommandOutcome
    {
        DB::transaction(function () use ($service, $command, $args, $error, $attempt, $kind): void {
            $this->openQueueItem($service, $command, $args, $error, $attempt, $kind);
            $this->effects->failed($service, $command, $args, $error);
        });

        ProvisioningFailed::dispatch($service->id, $service->client_id, $command->value, $error);

        return CommandOutcome::failed($error);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function openQueueItem(Service $service, ModuleCommandName $command, array $args, string $error, int $attempts, string $kind): void
    {
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

        $item->fill(['kind' => $kind, 'args' => $args, 'last_error' => $error, 'attempts' => $item->attempts + $attempts])->save();
    }
}
