<?php

namespace App\Modules\Services\Actions;

use App\Events\Services\ServiceCompleted;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Automation task (TERMINATIONS): services with a recurring cycle limit become "completed" once
 * every allowed renewal was invoiced and the last paid period has ended.
 */
class CompleteLimitedServices
{
    public function __construct(private ActivityLogger $activity) {}

    public function handle(CarbonImmutable $today): int
    {
        $count = 0;

        Service::query()
            ->where('status', ServiceStatus::Active->value)
            ->where('recurring_cycles_limit', '>', 0)
            ->whereColumn('renewals_invoiced', '>=', 'recurring_cycles_limit')
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', $today)
            ->orderBy('id')
            ->each(function (Service $service) use (&$count): void {
                if ($service->next_invoice_date !== null && $service->next_invoice_date->gt($service->next_due_date)) {
                    return;
                }

                $completed = DB::transaction(function () use ($service): bool {
                    $locked = Service::query()->lockForUpdate()->find($service->id);

                    if ($locked === null || ! $locked->status->canTransitionTo(ServiceStatus::Completed)) {
                        return false;
                    }

                    $locked->update(['status' => ServiceStatus::Completed, 'do_not_renew' => true]);
                    $locked->addons()->whereIn('status', ServiceStatus::occupyingValues())->update(['status' => ServiceStatus::Completed->value]);
                    $this->activity->log('Service completed (billing cycle limit reached)', $locked, $locked->client_id);

                    return true;
                });

                if ($completed) {
                    ServiceCompleted::dispatch($service->id, $service->client_id);
                    $count++;
                }
            });

        return $count;
    }
}
