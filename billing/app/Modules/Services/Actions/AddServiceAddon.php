<?php

namespace App\Modules\Services\Actions;

use App\Enums\BillingCycle;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Exceptions\ServiceActionException;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use Carbon\CarbonImmutable;

/**
 * Staff adds an addon to a live service without an invoice (services.md §11). The first renewal
 * is invoiced from next_due_date (default: the parent's next due date).
 */
class AddServiceAddon
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @param  array{name: string, addon_id?: ?int, cycle: string, recurring_amount: int, setup_fee: int, next_due_date?: ?string, status: string, suspend_parent_on_overdue?: bool}  $data
     */
    public function handle(Service $service, array $data, CarbonImmutable $today): ServiceAddon
    {
        if (! in_array($service->status, [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            throw new ServiceActionException(__('Addons can only be added to pending, active or suspended services.'));
        }

        $cycle = BillingCycle::from($data['cycle']);
        $nextDue = $cycle->isRecurring()
            ? (isset($data['next_due_date']) ? CarbonImmutable::parse($data['next_due_date']) : ($service->next_due_date ?? $cycle->advance($today)))
            : null;
        $active = $data['status'] === ServiceStatus::Active->value;

        $addon = $service->addons()->create([
            'addon_id' => $data['addon_id'] ?? null,
            'name' => $data['name'],
            'status' => $active ? ServiceStatus::Active : ServiceStatus::Pending,
            'cycle' => $cycle,
            'currency' => $service->currency,
            'setup_fee' => $data['setup_fee'],
            'recurring_amount' => $data['recurring_amount'],
            'registration_date' => $today,
            'billing_anchor_day' => $nextDue?->day,
            'next_due_date' => $nextDue,
            'next_invoice_date' => $nextDue,
            'activated_at' => $active ? CarbonImmutable::now() : null,
            'suspend_parent_on_overdue' => (bool) ($data['suspend_parent_on_overdue'] ?? false),
        ]);

        $this->activity->log("Addon {$addon->name} added", $service, $service->client_id, ['addon_id' => $addon->id]);

        return $addon;
    }
}
