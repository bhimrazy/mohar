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
use App\Modules\Services\Services\ProvisioningSnapshots;
use App\Modules\Services\Services\ServiceDrivers;
use App\Modules\Services\Support\Catalog;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Settles commands whose outcome was unknown by asking the driver what is on the server (ReconcilesCommands, ADR 0009
 * amendment 1). Generic: the driver decides what each command leaves behind; this class only applies the answer.
 *
 * - Executed: the command is applied as a success and its verify item closes (no driver call is repeated).
 * - Conflict, NotExecuted, InProgress, Unknown: the item stays open with what the check found. Staff decide; nothing
 *   is ever re-run from here. "Run again" is offered for a create only after NotExecuted (see mayRunAgain()).
 *
 * The scheduled sweep checks each item every few minutes for the first hour after the unknown attempt; after that,
 * only when staff press "Check now".
 */
class VerifyUnknownOutcomes
{
    /** How long after the unknown attempt the sweep keeps checking on its own. */
    public const SWEEP_WINDOW_MINUTES = 60;

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

        ModuleQueueItem::query()
            ->where('subject_type', ModuleQueueItem::SUBJECT_SERVICE)
            ->where('kind', ModuleQueueItem::KIND_VERIFY)
            ->where('status', QueueStatus::Open->value)
            ->orderBy('id')
            ->each(function (ModuleQueueItem $item) use ($since, &$checked): void {
                if ($this->attemptedAt($item)->greaterThanOrEqualTo($since) && $this->check($item) !== null) {
                    $checked++;
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
     * behind, so only a create waits: until the server check found nothing (NotExecuted) on a driver that can check.
     */
    public function runAgainBlockedReason(ModuleQueueItem $item): ?string
    {
        if ($item->command !== ModuleCommandName::Create->value || ! $this->canCheck($item) || $item->lookup_status === ReconcileStatus::NotExecuted) {
            return null;
        }

        return match ($item->lookup_status) {
            ReconcileStatus::Conflict => __('The server holds a conflicting account; fix it on the server, then check again.'),
            ReconcileStatus::InProgress => __('The server may still be creating the account; wait a few minutes and check again.'),
            default => __('Check the server first: creating again is offered once the check finds no account.'),
        };
    }

    /**
     * Ask the driver and record or apply the answer. Null when the driver cannot check.
     */
    public function check(ModuleQueueItem $item): ?ReconcileStatus
    {
        if (! $this->canCheck($item)) {
            return null;
        }

        $service = $item->service;
        $command = ModuleCommandName::from($item->command);
        $driver = $this->drivers->get($this->serviceDrivers->keyFor($service));
        $secrets = [];

        try {
            $context = $this->snapshots->context($service, $service->server, $this->catalog->product($service->product_id), $driver);
            $secrets = $context->secrets();
            $lookup = $driver instanceof ReconcilesCommands ? $driver->reconcile($context, $command->value, $this->attemptedAt($item)) : new CommandLookup(ReconcileStatus::Unknown);
        } catch (Throwable $exception) {
            report(new \RuntimeException($this->redactor->maskValues($exception->getMessage(), $secrets)));
            $lookup = new CommandLookup(ReconcileStatus::Unknown, message: __('The server check failed; try again later.'));
        }

        $message = $lookup->message === null ? null : mb_substr($this->redactor->maskValues($lookup->message, $secrets), 0, 255);

        if ($lookup->status === ReconcileStatus::Executed) {
            $data = $lookup->account !== null && $lookup->account->username !== $service->username ? ['username' => $lookup->account->username] : [];
            $this->commands->settle($service->id, $command, $item->args ?? [], DriverResult::ok($message ?? __('Confirmed on the server.'), $data));
            $this->activity->log("{$command->noun()} for {$service->label()} confirmed on the server", $service, $service->client_id, ['check' => $message]);

            return $lookup->status;
        }

        $item->update(['lookup_status' => $lookup->status, 'lookup_message' => $message, 'looked_up_at' => CarbonImmutable::now()]);

        return $lookup->status;
    }

    /**
     * When the attempt with the unknown outcome was made: the latest unknown module log entry for the command.
     */
    private function attemptedAt(ModuleQueueItem $item): CarbonImmutable
    {
        $at = ModuleCommand::query()
            ->where('service_id', $item->subject_id)
            ->where('command', $item->command)
            ->where('status', ModuleCommand::UNKNOWN)
            ->orderByDesc('id')
            ->value('created_at');

        return $at !== null ? CarbonImmutable::parse($at) : ($item->created_at ?? CarbonImmutable::now());
    }
}
