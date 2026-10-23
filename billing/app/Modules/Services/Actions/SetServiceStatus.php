<?php

namespace App\Modules\Services\Actions;

use App\Contracts\Core\CompanyClock;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Exceptions\ServiceActionException;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\ServiceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Staff "set status without module": changes the status locally (state machine still enforced)
 * and writes an audit entry, because the remote account is left untouched. Takes the per-service
 * module lock, so it can never interleave with a running driver command.
 */
class SetServiceStatus
{
    public function __construct(private ActivityLogger $activity, private ServiceSettings $settings, private CompanyClock $clock) {}

    /**
     * @throws ServiceActionException when a module command for the service is running
     */
    public function handle(Service $service, ServiceStatus $target, ?string $note = null): void
    {
        try {
            Cache::lock(ExecuteModuleCommand::lockKey($service->id), $this->settings->lockTtlSeconds())
                ->block(2, fn () => $this->apply($service, $target, $note));
        } catch (LockTimeoutException) {
            throw new ServiceActionException(__('A module action is running for this service. Try again in a moment.'));
        }
    }

    private function apply(Service $service, ServiceStatus $target, ?string $note): void
    {
        DB::transaction(function () use ($service, $target, $note): void {
            /** @var Service $locked */
            $locked = Service::query()->lockForUpdate()->findOrFail($service->id);
            $from = $locked->status;
            $from->assertCanTransitionTo($target);

            $attributes = ['status' => $target];

            if ($target === ServiceStatus::Active) {
                $attributes += ['activated_at' => $locked->activated_at ?? CarbonImmutable::now(), 'suspended_at' => null, 'suspension_reason' => null];
            } elseif ($target === ServiceStatus::Suspended) {
                $attributes += ['suspended_at' => CarbonImmutable::now(), 'suspension_reason' => 'staff:'.($note ?: 'status changed')];
            } elseif (in_array($target, [ServiceStatus::Terminated, ServiceStatus::Cancelled], true)) {
                $attributes += ['termination_date' => $this->clock->todayDate(), 'do_not_renew' => true];
            }

            $locked->update($attributes);
            $this->activity->log("Status set to {$target->value} without module call", $locked, $locked->client_id, ['from' => $from->value, 'note' => $note]);
        });
    }
}
