<?php

namespace App\Modules\Services\Services;

use App\Contracts\Services\ServiceRegistry;
use App\Data\Services\AddonDraft;
use App\Data\Services\ProvisionResult;
use App\Data\Services\ServiceDraft;
use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Exceptions\InvalidStatusTransition;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Support\Catalog;
use App\Modules\Services\Support\CommandOutcome;
use App\Modules\Services\Support\ServiceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Services' implementation of the ServiceRegistry contract used by Orders.
 *
 * Draft dates: `nextDueDate` is the end of the first period the order invoice covers (the date the
 * first renewal is due). The service starts with next_due_date = today (first period unpaid) and
 * next_invoice_date = draft nextDueDate (first period already invoiced by the order).
 */
class EloquentServiceRegistry implements ServiceRegistry
{
    public function __construct(private ModuleCommandDispatcher $commands, private Catalog $catalog, private ServiceSettings $settings) {}

    public function createPending(ServiceDraft $draft): int
    {
        $today = CarbonImmutable::today();
        $recurring = $draft->cycle->isRecurring();

        $product = $this->catalog->product($draft->productId);

        $service = Service::query()->create([
            'client_id' => $draft->clientId,
            'product_id' => $draft->productId,
            'product_name' => $product->name ?? "Product #{$draft->productId}",
            'order_id' => $draft->orderId,
            'status' => ServiceStatus::Pending,
            'cycle' => $draft->cycle,
            'currency' => strtoupper($draft->currency),
            'domain' => $draft->domain !== null ? strtolower($draft->domain) : null,
            'first_payment_amount' => $draft->firstPaymentAmount,
            'recurring_amount' => $draft->recurringAmount,
            'promotion_id' => $draft->promotionId,
            'promotion_cycles_remaining' => $draft->renewalDiscount > 0 ? $draft->promotionCyclesRemaining : null,
            'renewal_discount' => $draft->renewalDiscount,
            'recurring_cycles_limit' => $product->recurringCycleLimit ?? 0,
            'registration_date' => $today,
            'billing_anchor_day' => $recurring ? $draft->nextDueDate->day : null,
            'next_due_date' => $recurring ? $today : null,
            'next_invoice_date' => $recurring ? $draft->nextDueDate : null,
            'custom_fields' => $draft->customFields,
        ]);
        $service->syncConfigOptions($draft->configOptions);

        return $service->id;
    }

    public function createPendingAddon(AddonDraft $draft): int
    {
        $service = Service::query()->findOrFail($draft->serviceId);
        $today = CarbonImmutable::today();
        $recurring = $draft->cycle->isRecurring();
        $addonName = collect($this->catalog->priceBook()?->addonsFor($service->product_id, $service->currency) ?? [])
            ->firstWhere('id', $draft->addonId)['name'] ?? "Addon #{$draft->addonId}";

        $addon = ServiceAddon::query()->create([
            'service_id' => $service->id,
            'addon_id' => $draft->addonId,
            'name' => $addonName,
            'order_id' => $draft->orderId,
            'status' => ServiceStatus::Pending,
            'cycle' => $draft->cycle,
            'currency' => $service->currency,
            'setup_fee' => $draft->setupFee,
            'recurring_amount' => $draft->recurringAmount,
            'registration_date' => $today,
            'billing_anchor_day' => $recurring ? $draft->nextDueDate->day : null,
            'next_due_date' => $recurring ? $today : null,
            'next_invoice_date' => $recurring ? $draft->nextDueDate : null,
        ]);

        return $addon->id;
    }

    public function provision(int $serviceId): ProvisionResult
    {
        $service = Service::query()->findOrFail($serviceId);

        try {
            $outcome = $this->commands->runNow($service, ModuleCommandName::Create);
        } catch (InvalidStatusTransition $exception) {
            return new ProvisionResult(false, $exception->getMessage());
        }

        return match ($outcome->status) {
            CommandOutcome::QUEUED => new ProvisionResult(false, $outcome->message ?? 'Provisioning queued.', pending: true),
            CommandOutcome::RETRY => new ProvisionResult(false, 'Provisioning will be retried.', pending: true),
            default => new ProvisionResult($outcome->succeededNow(), $outcome->message),
        };
    }

    public function cancelPending(int $serviceId, string $status = 'cancelled'): void
    {
        $target = ServiceStatus::from($status);

        if (! in_array($target, [ServiceStatus::Cancelled, ServiceStatus::Fraud], true)) {
            throw new InvalidArgumentException("Pending services can only become cancelled or fraud, not [{$status}].");
        }

        Cache::lock(ExecuteModuleCommand::lockKey($serviceId), $this->settings->lockTtlSeconds())->block($this->settings->lockWaitSeconds(), fn () => DB::transaction(function () use ($serviceId, $target): void {
            /** @var Service $service */
            $service = Service::query()->lockForUpdate()->findOrFail($serviceId);
            if ($service->status !== ServiceStatus::Pending) {
                throw new InvalidStatusTransition($service->status, $target);
            }

            $service->update(['status' => $target, 'do_not_renew' => true]);

            $service->addons()->where('status', ServiceStatus::Pending->value)->update(['status' => $target->value]);
        }));
    }
}
