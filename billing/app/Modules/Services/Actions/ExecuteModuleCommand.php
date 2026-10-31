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
use App\Modules\Services\Enums\ServiceStatus;
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
     * A command whose outcome was unknown turned out to have run (ReconcilesCommands answered Executed): apply it as a
     * success, close its verify item and record the confirmation in the module log. Never calls the driver.
     *
     * The answer is applied only if, under the service lock and the item's row lock, the verify item is still open and
     * the attempt it describes is still the latest one with an unknown outcome. A second check, a staff dismissal or a
     * newer attempt in the meantime makes it a no-op (skipped).
     *
     * @param  array<string, mixed>  $args
     *
     * @throws LockTimeoutException when another command for the service holds the lock too long
     */
    public function settle(int $serviceId, ModuleCommandName $command, array $args, DriverResult $result, int $itemId, ?int $attemptId, string $initiator = 'system'): CommandOutcome
    {
        return Cache::lock(self::lockKey($serviceId), $this->settings->lockTtlSeconds($this->serviceDrivers->jobTimeoutFor($serviceId)))
            ->block($this->settings->lockWaitSeconds(), fn (): CommandOutcome => Service::withTrashed()->whereKey($serviceId)->exists()
                ? $this->succeeded($serviceId, $command, [...$args, 'verified' => true], $result, ['item' => $itemId, 'attempt' => $attemptId, 'initiator' => $initiator])
                : CommandOutcome::skipped('Service not found.'));
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

        $unknown = $jobDied && $this->markUnfinishedAsUnknown($service->id, $command) > 0;
        $this->openQueueItem($service, $command, $args, $unknown ? self::unknownMessage($error) : $error, 1, $unknown ? ModuleQueueItem::KIND_VERIFY : ModuleQueueItem::KIND_FAILURE);

        $this->effects->failed($service, $command, $args, $error);
        ProvisioningFailed::dispatch($service->id, $service->client_id, $command->value, $error, $unknown);
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
                ProvisioningFailed::dispatch($service->id, $service->client_id, $command->value, $error, outcomeUnknown: true);

                return CommandOutcome::failed($error);
            }
        }

        // "Create again" after a server check answers for exactly the attempt that was checked. Re-checked here, under
        // the lock and just before the driver runs: a newer attempt that is unfinished or of unknown outcome means the
        // answer no longer holds, so nothing is sent and the entry waits for a new check.
        if ($command === ModuleCommandName::Create && isset($args['checked_attempt']) && ! $this->awaitingVerification($service->id)) {
            // Staff ignored or closed the entry after clicking: the Create again it allowed is cancelled.
            return CommandOutcome::skipped('Not sent: the entry was closed after Create again was requested.');
        }

        if ($command === ModuleCommandName::Create && isset($args['checked_attempt']) && $this->newerAttemptSince($service->id, (int) $args['checked_attempt'])) {
            $error = 'Not sent: a newer create attempt has an unknown outcome since the server was checked. Check the server again.';
            $this->openQueueItem($service, $command, array_diff_key($args, ['verified' => true, 'checked_attempt' => true]), $error, 0, ModuleQueueItem::KIND_VERIFY);

            return CommandOutcome::failed($error);
        }

        $product = $this->catalog->product($service->product_id);
        $driverKey = $this->serviceDrivers->keyFor($service);
        $started = hrtime(true);
        $log = null;
        $context = null;
        $driver = null;
        $target = null;
        $declared = [];

        try {
            $driver = $this->drivers->get($driverKey);
            // Before anything is sent: a driver that cannot name its secret keys fails the command here, never logs unmasked.
            $declared = Redactor::declaredKeys($driver, $command->value);
            $server = $this->prepare($service, $command, $driver, $product?->serverGroupId, $driverKey);
            // What a create sends, recorded before the call: only an account matching it may later be adopted.
            $target = $command === ModuleCommandName::Create ? ['username' => $service->username, 'domain' => $service->domain, 'server_id' => $service->server_id] : null;
            $context = $this->snapshots->context($service, $server, $product, $driver, $deadline, $target === null ? null : $this->unknownAttemptAt($service));
            $log = $this->startLog($service, $driverKey, $command, $initiator, $attempt, $target);
            $result = $this->callDriver($driver, $context, $command, $args);
        } catch (RetryableProvisioningException $exception) {
            $result = DriverResult::fail($exception->getMessage(), retryable: true);
        } catch (NoServerCapacity $exception) {
            ServerCapacityExhausted::dispatch($service->id, $service->product_id, $product?->serverGroupId);
            $result = DriverResult::fail($exception->getMessage());
        } catch (Throwable $exception) {
            report(new \RuntimeException($this->redactor->maskValues($exception->getMessage(), $this->secrets($context, $args))));
            $result = DriverResult::fail($exception->getMessage());
        }

        $secrets = $this->secrets($context, $args);
        // Keys the driver declares secret in this command's payloads: masked by key in the log, and their values in its text.
        $declaredValues = $this->redactor->valuesUnder($declared, $result->request, $result->response);
        $error = $result->success ? null : $this->redactor->maskDeclared((string) $result->message, $declaredValues, $secrets);
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
            'request' => [...$this->redactor->redactDeclared($result->request, $declaredValues, $secrets, $declared), ...($target === null ? [] : ['target' => $target])] ?: null,
            'response' => $this->redactor->redactDeclared($result->response, $declaredValues, $secrets, $declared) ?: null,
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
     * @param  array{username: ?string, domain: ?string, server_id: ?int}|null  $target  what a create is about to send
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
            && (! array_key_exists('server_id', $target) || $target['server_id'] === $service->server_id)
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
            // Only services that hold an account count; the server's own check decides about the rest.
            if (Service::query()->whereIn('status', ServiceStatus::occupyingValues())->where('server_id', $service->server_id)->where('username', $candidate)->whereKeyNot($service->id)->exists()) {
                continue;
            }

            try {
                // The unique (server_id, username) index over live services settles a race with another service. The
                // write runs in its own transaction (a savepoint inside an open one), so a refused statement leaves any
                // surrounding transaction usable on PostgreSQL (rule 7).
                DB::transaction(fn () => Service::withTrashed()->whereKey($service->id)->update(['username' => $candidate]));
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
     * Whether a create attempt after the checked one is unfinished or of unknown outcome.
     */
    private function newerAttemptSince(int $serviceId, int $checkedAttemptId): bool
    {
        return ModuleCommand::query()
            ->where('service_id', $serviceId)
            ->where('command', ModuleCommandName::Create->value)
            ->where('id', '>', $checkedAttemptId)
            ->whereIn('status', [ModuleCommand::UNKNOWN, ModuleCommand::RUNNING])
            ->exists();
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
            $message = $this->redactor->maskValues($exception->getMessage(), $this->secrets($context, $args));
            report(new \RuntimeException($message));

            return DriverResult::unknown("The {$driver->name()} driver failed during the call: {$message}");
        }
    }

    /**
     * The values to mask in anything this command reports or stores: the context's secrets and a new password.
     *
     * @param  array<string, mixed>  $args
     * @return list<string>
     */
    private function secrets(?ProvisioningContext $context, array $args): array
    {
        return [...($context?->secrets() ?? []), ...(isset($args['password']) ? [(string) $args['password']] : [])];
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
    /**
     * @param  array<string, mixed>  $args
     * @param  array{item: int, attempt: ?int, initiator: string}|null  $settling  set when a server check settles an unknown
     *                                                                             outcome (settle())
     */
    private function succeeded(int $serviceId, ModuleCommandName $command, array $args, DriverResult $result, ?array $settling = null): CommandOutcome
    {
        $outcome = DB::transaction(function () use ($serviceId, $command, $args, $result, $settling): array {
            /** @var Service $service */
            $service = Service::withTrashed()->lockForUpdate()->findOrFail($serviceId);

            if ($settling !== null && ! $this->stillUnsettled($service, $command, $settling)) {
                return ['skipped' => true, 'conflict' => null, 'events' => [], 'client_id' => $service->client_id];
            }

            if ($settling !== null) {
                $this->startLog($service, $this->serviceDrivers->keyFor($service), $command, $settling['initiator'], 1)->update([
                    'success' => true,
                    'status' => ModuleCommand::SUCCEEDED,
                    'response' => ['verified' => (string) $result->message],
                ]);
            }
            $target = CommandEffects::targetStatus($command, $args);
            $conflict = match (true) {
                $target !== null && ! $service->status->canTransitionTo($target) => "The service became {$service->status->value} while {$command->label()} ran; the remote account was changed but the local status was not.",
                isset($args['cancellation_request_id']) && ! $this->requestIsOpen((int) $args['cancellation_request_id']) => 'The cancellation request was voided while the account was being terminated; check the server.',
                default => null,
            };

            if ($conflict !== null) {
                $this->openQueueItem($service, $command, $args, $conflict, 1, ModuleQueueItem::KIND_RECONCILE);
                $this->activity->log("{$command->noun()} for {$service->label()} needs reconciling", $service, $service->client_id, ['reason' => $conflict]);

                return ['skipped' => false, 'conflict' => $conflict, 'events' => [], 'client_id' => $service->client_id];
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

            return ['skipped' => false, 'conflict' => null, 'events' => $events, 'client_id' => $service->client_id];
        });

        if ($outcome['skipped']) {
            return CommandOutcome::skipped('The entry was settled or changed meanwhile.');
        }

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

        // A verify item means the provider may have acted: staff get the "being checked" notice, not a failure.
        ProvisioningFailed::dispatch($service->id, $service->client_id, $command->value, $error, $kind === ModuleQueueItem::KIND_VERIFY);

        return CommandOutcome::failed($error);
    }

    /**
     * Under the service row lock: the verify item is still open and still a verify item, and the attempt it describes
     * is still the latest one of the command with an unknown outcome.
     *
     * @param  array{item: int, attempt: ?int, initiator: string}  $settling
     */
    private function stillUnsettled(Service $service, ModuleCommandName $command, array $settling): bool
    {
        $item = ModuleQueueItem::query()->lockForUpdate()->find($settling['item']);
        $latest = ModuleCommand::query()
            ->where('service_id', $service->id)
            ->where('command', $command->value)
            ->where('status', ModuleCommand::UNKNOWN)
            ->orderByDesc('id')
            ->value('id');

        return $item !== null
            && $item->status === QueueStatus::Open
            && $item->kind === ModuleQueueItem::KIND_VERIFY
            && $item->subject_id === $service->id
            && $latest !== null
            && $settling['attempt'] !== null
            && (int) $latest === $settling['attempt'];
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

        // A new failure or unknown outcome makes any earlier server check stale: it described an older attempt.
        $item->fill([
            'kind' => $kind, 'args' => $args, 'last_error' => $error, 'attempts' => $item->attempts + $attempts,
            'lookup_status' => null, 'lookup_message' => null, 'looked_up_at' => null, 'lookup_command_id' => null,
        ])->save();
    }
}
