<?php

namespace App\Modules\Services\Actions;

use App\Contracts\Services\Provisioning\ReconcilesCommands;
use App\Data\Services\Provisioning\CommandLookup;
use App\Data\Services\Provisioning\DriverResult;
use App\Enums\ReconcileStatus;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\QueueStatus;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ProvisioningSnapshots;
use App\Modules\Services\Services\ServiceDrivers;
use App\Modules\Services\Support\Catalog;
use App\Modules\Services\Support\CommandOutcome;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Throwable;

/**
 * Settles commands whose outcome was unknown by asking the driver what is on the server (ReconcilesCommands, ADR 0009
 * amendment 1). Generic: the driver decides what each command leaves behind; this class only applies the answer.
 *
 * - Executed: the command is applied as a success and its verify item closes (no driver call is repeated).
 * - Conflict, NotExecuted, InProgress, Unknown: the item stays open with what the check found. Staff decide; nothing
 *   is ever re-run from here. "Create again" is offered for a create only after a recent NotExecuted about the
 *   latest attempt (runAgainBlockedReason()).
 *
 * The scheduled sweep checks each item every few minutes for the first hour after the unknown attempt; after that,
 * only when staff press "Check now".
 */
class VerifyUnknownOutcomes
{
    /** How long after the unknown attempt the sweep keeps checking on its own. */
    public const SWEEP_WINDOW_MINUTES = 60;

    /** A not-executed answer older than this no longer opens "Create again": check again first. */
    public const FRESH_CHECK_MINUTES = 15;

    public function __construct(
        private ProvisioningDriverRegistry $drivers,
        private ServiceDrivers $serviceDrivers,
        private ProvisioningSnapshots $snapshots,
        private Catalog $catalog,
        private ExecuteModuleCommand $commands,
        private ActivityLogger $activity,
        private Redactor $redactor,
    ) {}

    /**
     * @return int the number of items checked
     */
    public function sweep(): int
    {
        $checked = 0;
        $since = CarbonImmutable::now()->subMinutes(self::SWEEP_WINDOW_MINUTES);

        // An item is saved when its attempt turns unknown, so updated_at bounds the window in SQL (a safe superset);
        // id-based paging, because settling an item drops it out of the query.
        ModuleQueueItem::query()
            ->with(['service.server', 'service.client'])
            ->where('subject_type', ModuleQueueItem::SUBJECT_SERVICE)
            ->where('kind', ModuleQueueItem::KIND_VERIFY)
            ->where('status', QueueStatus::Open->value)
            ->where('updated_at', '>=', $since)
            ->lazyById(100)
            ->each(function (ModuleQueueItem $item) use ($since, &$checked): void {
                if ($this->attemptedAt($item)->lessThan($since)) {
                    return;
                }

                try {
                    if ($this->check($item) !== null) {
                        $checked++;
                    }
                } catch (Throwable $exception) {
                    // One item that cannot be checked never stops the others.
                    report($exception);
                }
            });

        return $checked;
    }

    /**
     * Whether the item's driver can check the server at all.
     */
    public function canCheck(ModuleQueueItem $item): bool
    {
        $service = $item->service;

        if ($item->kind !== ModuleQueueItem::KIND_VERIFY || $item->status !== QueueStatus::Open || $service === null) {
            return false;
        }

        $key = $this->serviceDrivers->keyFor($service);

        return $this->drivers->has($key) && $this->drivers->get($key) instanceof ReconcilesCommands;
    }

    /**
     * Why staff may not run the command again yet, or null when they may. Only a create can leave a second account
     * behind, so only a create waits, on a driver that can check: until a recent check of the latest attempt found
     * nothing on the server (NotExecuted).
     */
    public function runAgainBlockedReason(ModuleQueueItem $item): ?string
    {
        if ($item->command !== ModuleCommandName::Create->value || ! $this->canCheck($item)) {
            return null;
        }

        $attempt = $this->unknownAttempt($item);
        $aboutLatest = $item->lookup_command_id !== null && $item->lookup_command_id === $attempt?->id;
        $fresh = $item->looked_up_at !== null && $item->looked_up_at->greaterThanOrEqualTo(CarbonImmutable::now()->subMinutes(self::FRESH_CHECK_MINUTES));

        if ($item->lookup_status === ReconcileStatus::NotExecuted && $aboutLatest && $fresh) {
            return null;
        }

        return match (true) {
            $item->lookup_status === ReconcileStatus::NotExecuted && ! $aboutLatest => __('Check now first: the last check was about an earlier attempt.'),
            $item->lookup_status === ReconcileStatus::NotExecuted => __('Check now first: the last check is more than :minutes minutes old.', ['minutes' => self::FRESH_CHECK_MINUTES]),
            $item->lookup_status === ReconcileStatus::Conflict => __('The server holds a conflicting account. Fix it on the server and check again, or mark the entry fixed.'),
            $item->lookup_status === ReconcileStatus::InProgress => __('The server may still be creating the account. Wait a few minutes, then check again.'),
            $item->lookup_status === ReconcileStatus::Unknown => __('The server could not be checked. Check again later, or mark the entry fixed once you have checked the server yourself.'),
            default => __('Check now first: Create again is offered once the check finds no account on the server.'),
        };
    }

    /**
     * Ask the driver and record or apply the answer. Null when the driver cannot check, or when the entry was settled
     * or changed meanwhile (nothing is applied then).
     *
     * @param  string  $initiator  who asked: `system` (the sweep) or `staff:<id>` (Check now)
     */
    public function check(ModuleQueueItem $item, string $initiator = 'system'): ?ReconcileStatus
    {
        if (! $this->canCheck($item)) {
            return null;
        }

        $service = $item->service;
        $command = ModuleCommandName::from($item->command);
        $driver = $this->drivers->get($this->serviceDrivers->keyFor($service));
        $attempt = $this->unknownAttempt($item);
        $secrets = [];

        try {
            $lookup = $this->changedSinceAttempt($service, $attempt);

            if ($lookup === null) {
                $context = $this->snapshots->context($service, $service->server, $this->catalog->product($service->product_id), $driver);
                $secrets = $context->secrets();
                $lookup = $driver instanceof ReconcilesCommands ? $driver->reconcile($context, $command->value, $this->attemptedAt($item)) : new CommandLookup(ReconcileStatus::Unknown);
            }
        } catch (Throwable $exception) {
            report(new \RuntimeException($this->redactor->maskValues($exception->getMessage(), $secrets)));
            $lookup = new CommandLookup(ReconcileStatus::Unknown, message: __('The server check failed; try again later.'));
        }

        $message = $lookup->message === null ? null : mb_substr($this->redactor->maskValues($lookup->message, $secrets), 0, 255);

        if ($lookup->status === ReconcileStatus::Executed) {
            try {
                $outcome = $this->commands->settle($service->id, $command, $item->args ?? [], DriverResult::ok($message ?? __('Confirmed on the server.')), $item->id, $attempt?->id, $initiator);
            } catch (LockTimeoutException) {
                $this->record($item, ReconcileStatus::InProgress, __('Busy: another action is running for this service. Try again in a moment; the sweep also checks it again.'), $attempt);

                return ReconcileStatus::InProgress;
            }

            if ($outcome->status === CommandOutcome::SKIPPED) {
                return null;
            }

            $this->activity->log("{$command->noun()} for {$service->label()} confirmed on the server", $service, $service->client_id, ['check' => $message, 'by' => $initiator]);

            return $lookup->status;
        }

        $this->record($item, $lookup->status, $message, $attempt);

        return $lookup->status;
    }

    /**
     * Record a check on the item, if it is still open, tied to the attempt it described.
     */
    private function record(ModuleQueueItem $item, ReconcileStatus $status, ?string $message, ?ModuleCommand $attempt): void
    {
        ModuleQueueItem::query()->whereKey($item->id)->where('status', QueueStatus::Open->value)->update([
            'lookup_status' => $status->value,
            'lookup_message' => $message,
            'looked_up_at' => CarbonImmutable::now(),
            'lookup_command_id' => $attempt?->id,
        ]);
    }

    /**
     * A conflict for staff when the service moved to another server, or its username or domain changed, after the
     * attempt: the server the attempt went to cannot be asked through the service any more, and an answer about the
     * new target would say nothing about what the attempt left behind. Null when nothing changed.
     */
    private function changedSinceAttempt(Service $service, ?ModuleCommand $attempt): ?CommandLookup
    {
        $target = $attempt?->request['target'] ?? null;

        if (! is_array($target)) {
            return null;
        }

        if (array_key_exists('server_id', $target) && $target['server_id'] !== $service->server_id) {
            $server = is_int($target['server_id']) ? Server::query()->find($target['server_id']) : null;

            return new CommandLookup(ReconcileStatus::Conflict, message: __('The service moved to another server after this attempt; check :server by hand for the account.', ['server' => $server->name ?? __('the earlier server')]));
        }

        if (($target['username'] ?? null) !== $service->username || ($target['domain'] ?? null) !== $service->domain) {
            return new CommandLookup(ReconcileStatus::Conflict, message: __('The username or domain was changed after this attempt; check the server by hand for :username.', ['username' => (string) ($target['username'] ?? '')]));
        }

        return null;
    }

    /**
     * When the attempt with the unknown outcome was made: the latest unknown module log entry for the command.
     */
    private function attemptedAt(ModuleQueueItem $item): CarbonImmutable
    {
        return $this->unknownAttempt($item)->created_at ?? $item->created_at ?? CarbonImmutable::now();
    }

    private function unknownAttempt(ModuleQueueItem $item): ?ModuleCommand
    {
        return ModuleCommand::query()
            ->where('service_id', $item->subject_id)
            ->where('command', $item->command)
            ->where('status', ModuleCommand::UNKNOWN)
            ->orderByDesc('id')
            ->first();
    }
}
