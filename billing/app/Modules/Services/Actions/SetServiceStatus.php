<?php

namespace App\Modules\Services\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff "set status without module": changes the status locally (state machine still enforced)
 * and writes an audit entry, because the remote account is left untouched.
 */
class SetServiceStatus
{
    public function __construct(private ActivityLogger $activity) {}

    public function handle(Service $service, ServiceStatus $target, ?string $note = null): void
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
                $attributes += ['termination_date' => CarbonImmutable::today(), 'do_not_renew' => true];
            }

            $locked->update($attributes);
            $this->activity->log("Status set to {$target->value} without module call", $locked, $locked->client_id, ['from' => $from->value, 'note' => $note]);
        });
    }
}
