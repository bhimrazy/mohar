<?php

namespace App\Modules\Services\Actions;

use App\Events\Services\ServiceCancellationRequested;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Enums\CancellationType;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Exceptions\ServiceActionException;
use App\Modules\Services\Models\CancellationRequest;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\ServiceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records a cancellation request (services.md §6): stops renewals, optionally cancels unpaid
 * renewal invoices, and schedules processing for today or the end of the paid period.
 */
class RequestCancellation
{
    public function __construct(
        private CancelOpenRenewals $cancelRenewals,
        private ServiceSettings $settings,
        private ActivityLogger $activity,
    ) {}

    public function handle(Service $service, CancellationType $type, ?string $reason, ?User $requestedBy, bool $byStaff, CarbonImmutable $today): CancellationRequest
    {
        $request = DB::transaction(function () use ($service, $type, $reason, $requestedBy, $byStaff, $today): CancellationRequest {
            /** @var Service $locked */
            $locked = Service::query()->lockForUpdate()->findOrFail($service->id);

            if (! in_array($locked->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
                throw new ServiceActionException(__('Only active or suspended services can be cancelled.'));
            }

            if ($locked->cancellationRequests()->where('status', CancellationStatus::Open->value)->exists()) {
                throw new ServiceActionException(__('A cancellation request is already open for this service.'));
            }

            $processAfter = $type === CancellationType::Immediate || $locked->next_due_date === null || $locked->next_due_date->lt($today)
                ? $today
                : $locked->next_due_date;

            $request = $locked->cancellationRequests()->create([
                'type' => $type,
                'reason' => $reason,
                'requested_by_user_id' => $requestedBy?->id,
                'by_staff' => $byStaff,
                'status' => CancellationStatus::Open,
                'process_after' => $processAfter,
            ]);

            $locked->update(['do_not_renew' => true]);

            if ($this->settings->cancelUnpaidInvoicesOnCancellation()) {
                $this->cancelRenewals->handle($locked);
            }

            $this->activity->log('Cancellation requested ('.$type->label().')', $locked, $locked->client_id, ['reason' => $reason]);

            return $request;
        });

        ServiceCancellationRequested::dispatch($service->id, $service->client_id, $type->value, $reason);

        return $request;
    }
}
