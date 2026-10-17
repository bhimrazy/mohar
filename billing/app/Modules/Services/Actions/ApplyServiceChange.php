<?php

namespace App\Modules\Services\Actions;

use App\Enums\BillingCycle;
use App\Events\Services\ServiceUpgraded;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Enums\ChangeKind;
use App\Modules\Services\Enums\ChangeStatus;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceChange;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies a paid (or zero/negative) upgrade/downgrade: billing fields first, then the driver's
 * change_package. Unpaid renewals at the old price are cancelled and re-invoiced by the next run.
 * Idempotent: an already-applied change is ignored.
 */
class ApplyServiceChange
{
    public function __construct(
        private CancelOpenRenewals $cancelRenewals,
        private ModuleCommandDispatcher $commands,
        private ActivityLogger $activity,
    ) {}

    public function handle(int $changeId): void
    {
        $applied = DB::transaction(function () use ($changeId): ?ServiceChange {
            $change = ServiceChange::query()->lockForUpdate()->find($changeId);

            if ($change === null || $change->status !== ChangeStatus::PendingPayment) {
                return null;
            }

            /** @var Service $service */
            $service = Service::query()->lockForUpdate()->findOrFail($change->service_id);
            $to = $change->to_snapshot;

            $service->fill([
                'product_id' => $to['product_id'],
                'product_name' => $to['product_name'],
                'cycle' => BillingCycle::from($to['cycle']),
                'recurring_amount' => $to['recurring_amount'],
            ]);

            $rebased = $change->kind === ChangeKind::Cycle || $change->from_snapshot['next_due_date'] === null;

            if ($rebased && $to['next_due_date'] !== null) {
                $service->next_due_date = CarbonImmutable::parse($to['next_due_date']);
                $service->billing_anchor_day = $to['billing_anchor_day'];
            }

            $cancelled = $this->cancelRenewals->handle($service, $rebased ? null : $service->next_due_date);

            if ($cancelled > 0 || $rebased) {
                $service->next_invoice_date = $service->next_due_date;
            }

            $service->save();
            $change->update(['status' => ChangeStatus::Applied, 'applied_at' => CarbonImmutable::now()]);
            $this->activity->log("Service changed to {$to['product_name']}", $service, $service->client_id, ['change_id' => $change->id]);

            return $change;
        });

        if ($applied === null) {
            return;
        }

        $service = Service::query()->findOrFail($applied->service_id);
        ServiceUpgraded::dispatch($service->id, $service->client_id, $applied->from_snapshot['product_id'], $applied->to_snapshot['product_id']);

        if (in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            $this->commands->queue($service, ModuleCommandName::ChangePackage, ['change_id' => $applied->id, 'previous' => $applied->from_snapshot]);
        }
    }
}
