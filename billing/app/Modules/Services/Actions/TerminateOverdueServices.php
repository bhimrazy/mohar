<?php

namespace App\Modules\Services\Actions;

use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Modules\Services\Services\OverduePolicy;
use App\Modules\Services\Support\Catalog;
use App\Modules\Services\Support\ServiceSettings;
use Carbon\CarbonImmutable;

/**
 * Automation task (TERMINATIONS): terminates services overdue for at least the terminate
 * threshold. Disabled by default (services.terminate.enabled = false). Addons follow the parent.
 */
class TerminateOverdueServices
{
    public function __construct(
        private OverduePolicy $overdue,
        private ModuleCommandDispatcher $commands,
        private ServiceSettings $settings,
        private Catalog $catalog,
    ) {}

    public function handle(CarbonImmutable $today): int
    {
        if (! $this->settings->terminateEnabled()) {
            return 0;
        }

        $count = 0;

        Service::query()
            ->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<', $today)
            ->eachById(function (Service $service) use ($today, &$count): void {
                if ($service->isExemptFromAutomation($today)) {
                    return;
                }

                $days = $this->overdue->daysOverdue($service, $today);

                if ($days !== null && $days >= ($service->terminate_days_override ?? $this->catalog->product($service->product_id)->terminateAfterDays ?? $this->settings->terminateDays())) {
                    $this->commands->queue($service, ModuleCommandName::Terminate);
                    $count++;
                }
            });

        return $count;
    }
}
