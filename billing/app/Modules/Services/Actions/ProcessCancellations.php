<?php

namespace App\Modules\Services\Actions;

use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\CancellationRequest;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Modules\Services\Support\ServiceSettings;
use Carbon\CarbonImmutable;

/**
 * Automation task (CANCELLATIONS): terminates services whose cancellation is due — open requests
 * past process_after (when auto-processing is on) and services flagged terminate_at_period_end.
 * The service ends as "cancelled"; requests are marked processed when the driver call succeeds.
 */
class ProcessCancellations
{
    public function __construct(private ModuleCommandDispatcher $commands, private ServiceSettings $settings) {}

    public function handle(CarbonImmutable $today): int
    {
        $count = 0;

        if ($this->settings->autoProcessCancellations()) {
            CancellationRequest::query()
                ->with('service')
                ->where('status', CancellationStatus::Open->value)
                ->whereDate('process_after', '<=', $today)
                ->orderBy('id')
                ->each(function (CancellationRequest $request) use (&$count): void {
                    if ($this->process($request)) {
                        $count++;
                    }
                });
        }

        Service::query()
            ->where('terminate_at_period_end', true)
            ->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', $today)
            ->whereDoesntHave('cancellationRequests', fn ($query) => $query->where('status', CancellationStatus::Open->value))
            ->orderBy('id')
            ->each(function (Service $service) use (&$count): void {
                $this->commands->queue($service, ModuleCommandName::Terminate, ['final_status' => ServiceStatus::Cancelled->value]);
                $count++;
            });

        return $count;
    }

    /**
     * Process one request now (staff approval or automation).
     */
    public function process(CancellationRequest $request): bool
    {
        $service = $request->service;

        if (! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            $request->update(['status' => CancellationStatus::Processed, 'processed_at' => CarbonImmutable::now()]);

            return false;
        }

        $this->commands->queue($service, ModuleCommandName::Terminate, [
            'final_status' => ServiceStatus::Cancelled->value,
            'cancellation_request_id' => $request->id,
        ]);

        return true;
    }
}
