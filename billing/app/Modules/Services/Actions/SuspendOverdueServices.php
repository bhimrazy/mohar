<?php

namespace App\Modules\Services\Actions;

use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Services\AddonLifecycle;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Modules\Services\Services\OverduePolicy;
use App\Modules\Services\Support\Catalog;
use App\Modules\Services\Support\ServiceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Automation task (SUSPENSIONS): suspends active services and addons overdue for at least
 * the suspend threshold (service override, else product override, else setting), unless exempt (services.md §4.5).
 */
class SuspendOverdueServices
{
    public function __construct(
        private OverduePolicy $overdue,
        private ModuleCommandDispatcher $commands,
        private AddonLifecycle $addons,
        private ServiceSettings $settings,
        private Catalog $catalog,
    ) {}

    public function handle(CarbonImmutable $today): int
    {
        if (! $this->settings->suspendEnabled()) {
            return 0;
        }

        $count = 0;

        Service::query()
            ->where('status', ServiceStatus::Active->value)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<', $today)
            ->orderBy('id')
            ->each(function (Service $service) use ($today, &$count): void {
                if ($this->shouldSuspend($service, $service->suspend_days_override ?? $this->catalog->product($service->product_id)?->suspendAfterDays, $today)) {
                    $this->commands->queue($service, ModuleCommandName::Suspend, ['reason' => 'overdue']);
                    $count++;
                }
            });

        ServiceAddon::query()
            ->with('service')
            ->whereIn('service_id', Service::query()->select('id'))
            ->where('status', ServiceStatus::Active->value)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<', $today)
            ->orderBy('id')
            ->each(function (ServiceAddon $addon) use ($today, &$count): void {
                if (! $this->shouldSuspend($addon, $addon->service->suspend_days_override ?? $this->catalog->product($addon->service->product_id)?->suspendAfterDays, $today)) {
                    return;
                }

                DB::transaction(fn () => $this->addons->transition($addon, ServiceStatus::Suspended, 'overdue'));
                $count++;

                if ($addon->suspend_parent_on_overdue && $addon->service->status === ServiceStatus::Active) {
                    $this->commands->queue($addon->service, ModuleCommandName::Suspend, ['reason' => 'addon_overdue']);
                }
            });

        return $count;
    }

    private function shouldSuspend(Service|ServiceAddon $item, ?int $override, CarbonImmutable $today): bool
    {
        if ($item->isExemptFromAutomation($today)) {
            return false;
        }

        $days = $this->overdue->daysOverdue($item, $today);

        return $days !== null && $days >= ($override ?? $this->settings->suspendDays());
    }
}
