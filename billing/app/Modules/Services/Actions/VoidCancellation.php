<?php

namespace App\Modules\Services\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Exceptions\ServiceActionException;
use App\Modules\Services\Models\CancellationRequest;
use App\Modules\Services\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws a cancellation request: renewals resume and periods whose invoices were cancelled
 * are invoiced again by the next renewal run.
 */
class VoidCancellation
{
    public function __construct(private ActivityLogger $activity) {}

    public function handle(CancellationRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            /** @var CancellationRequest $locked */
            $locked = CancellationRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! in_array($locked->status, [CancellationStatus::Open, CancellationStatus::Failed], true)) {
                throw new ServiceActionException(__('Only open cancellation requests can be voided.'));
            }

            /** @var Service $service */
            $service = Service::query()->lockForUpdate()->findOrFail($locked->service_id);

            $locked->update(['status' => CancellationStatus::Voided]);
            $service->update(['do_not_renew' => false, 'terminate_at_period_end' => false, 'next_invoice_date' => $service->next_due_date]);
            $this->activity->log('Cancellation request voided', $service, $service->client_id);
        });
    }
}
