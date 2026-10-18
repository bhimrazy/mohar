<?php

namespace App\Modules\Services\Services;

use App\Events\Services\ServiceActivated;
use App\Events\Services\ServiceCancelled;
use App\Events\Services\ServiceSuspended;
use App\Events\Services\ServiceTerminated;
use App\Events\Services\ServiceUnsuspended;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Drivers\Provisioning\DriverResult;
use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Enums\ChangeStatus;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\CancellationRequest;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceChange;
use Carbon\CarbonImmutable;

/**
 * Applies the local consequences of a module command (status, dates, credentials, cascades).
 * Called inside a transaction with the service row locked.
 */
class CommandEffects
{
    public function __construct(private AddonLifecycle $addons, private ActivityLogger $activity) {}

    /**
     * Target status of a command for the given args (terminate may end as cancelled).
     *
     * @param  array<string, mixed>  $args
     */
    public static function targetStatus(ModuleCommandName $command, array $args): ?ServiceStatus
    {
        if ($command === ModuleCommandName::Terminate && ($args['final_status'] ?? null) === ServiceStatus::Cancelled->value) {
            return ServiceStatus::Cancelled;
        }

        return $command->targetStatus();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<object> events to dispatch after commit
     */
    public function apply(Service $service, ModuleCommandName $command, array $args, DriverResult $result): array
    {
        $today = CarbonImmutable::today();
        $events = [];

        switch ($command) {
            case ModuleCommandName::Create:
                $firstActivation = $service->activated_at === null;
                $recreated = $service->status === ServiceStatus::Terminated;
                $service->fill(array_filter([
                    'username' => $result->data['username'] ?? null,
                    'password' => $result->data['password'] ?? null,
                    'dedicated_ip' => $result->data['dedicated_ip'] ?? null,
                ]));
                if (isset($result->data['driver_data']) && is_array($result->data['driver_data'])) {
                    $service->driver_data = [...($service->driver_data ?? []), ...$result->data['driver_data']];
                }
                if ($recreated && $service->cycle->isRecurring()) {
                    // A re-created account starts a fresh billing period today; the next renewal run invoices it.
                    $service->fill(['next_due_date' => $today, 'next_invoice_date' => $today, 'billing_anchor_day' => $today->day]);
                }
                $service->status = ServiceStatus::Active;
                $service->activated_at ??= CarbonImmutable::now();
                $service->termination_date = null;
                $service->do_not_renew = false;
                $service->terminate_at_period_end = false;
                $service->save();
                if ($firstActivation) {
                    $events[] = new ServiceActivated($service->id, $service->client_id, $service->next_due_date?->toDateString());
                }
                break;

            case ModuleCommandName::Suspend:
                $reason = (string) ($args['reason'] ?? 'staff');
                $service->update(['status' => ServiceStatus::Suspended, 'suspended_at' => CarbonImmutable::now(), 'suspension_reason' => $reason]);
                $this->addons->cascadeSuspend($service);
                $events[] = new ServiceSuspended($service->id, $service->client_id, $reason);
                break;

            case ModuleCommandName::Unsuspend:
                $service->update(['status' => ServiceStatus::Active, 'suspended_at' => null, 'suspension_reason' => null]);
                $this->addons->cascadeUnsuspend($service);
                $events[] = new ServiceUnsuspended($service->id, $service->client_id);
                break;

            case ModuleCommandName::Terminate:
                $final = self::targetStatus($command, $args) ?? ServiceStatus::Terminated;
                $service->update(['status' => $final, 'termination_date' => $today, 'do_not_renew' => true]);
                $this->addons->cancelAll($service, $today);
                // The service is gone: its own request and any other open request are done.
                $service->cancellationRequests()->where('status', CancellationStatus::Open->value)
                    ->update(['status' => CancellationStatus::Processed->value, 'processed_at' => CarbonImmutable::now()]);
                $events[] = $final === ServiceStatus::Cancelled
                    ? new ServiceCancelled($service->id, $service->client_id)
                    : new ServiceTerminated($service->id, $service->client_id);
                break;

            case ModuleCommandName::ChangePassword:
                $service->update(['password' => (string) $args['password']]);
                break;

            case ModuleCommandName::ChangePackage:
                break;
        }

        $this->activity->log("Service {$command->label()} succeeded", $service, $service->client_id);

        return $events;
    }

    /**
     * Side effects of a command that failed for good.
     *
     * @param  array<string, mixed>  $args
     */
    public function failed(Service $service, ModuleCommandName $command, array $args, string $error): void
    {
        if (isset($args['change_id'])) {
            ServiceChange::query()->whereKey((int) $args['change_id'])->update(['status' => ChangeStatus::ApplyFailed->value]);
        }

        if (isset($args['cancellation_request_id'])) {
            CancellationRequest::query()->whereKey((int) $args['cancellation_request_id'])
                ->where('status', CancellationStatus::Open->value)
                ->update(['status' => CancellationStatus::Failed->value]);
        }

        $this->activity->log("Service {$command->label()} failed: {$error}", $service, $service->client_id);
    }
}
