<?php

namespace App\Modules\Domains\Listeners;

use App\Events\Services\ServiceActivated;
use App\Events\Services\ServiceRenewed;
use App\Events\Services\ServiceSuspended;
use App\Events\Services\ServiceTerminated;
use App\Events\Services\ServiceUnsuspended;
use App\Modules\Domains\Models\Domain;
use Carbon\CarbonImmutable;

/**
 * Mirror the status and due date of a free domain's linked hosting service from Services events,
 * for the free-domain renewal guard (spec §4.3) without importing the Services module.
 */
class MirrorLinkedServiceState
{
    public function handle(ServiceActivated|ServiceRenewed|ServiceSuspended|ServiceUnsuspended|ServiceTerminated $event): void
    {
        $attributes = match (true) {
            $event instanceof ServiceRenewed => ['linked_service_status' => 'active', 'linked_service_due_date' => CarbonImmutable::parse($event->nextDueDate)],
            $event instanceof ServiceSuspended => ['linked_service_status' => 'suspended'],
            $event instanceof ServiceTerminated => ['linked_service_status' => 'terminated'],
            default => ['linked_service_status' => 'active'],
        };

        Domain::query()->where('linked_service_id', $event->serviceId)->update($attributes);
    }
}
