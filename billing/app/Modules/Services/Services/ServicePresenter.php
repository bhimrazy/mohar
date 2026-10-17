<?php

namespace App\Modules\Services\Services;

use App\Enums\BillingCycle;
use App\Modules\Services\Actions\QuoteServiceChange;
use App\Modules\Services\Drivers\Provisioning\DriverMetadata;
use App\Modules\Services\Drivers\Provisioning\DriverRegistry;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Exceptions\ServiceActionException;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\Catalog;
use Carbon\CarbonImmutable;

/**
 * View data shared by the admin and client service pages: available driver actions and
 * priced upgrade/downgrade options.
 */
class ServicePresenter
{
    public function __construct(
        private Catalog $catalog,
        private DriverRegistry $drivers,
        private QuoteServiceChange $quotes,
    ) {}

    public function driver(Service $service): DriverMetadata
    {
        $key = $this->catalog->product($service->product_id)->provisioningDriver ?? $service->server->driver ?? 'none';

        return $this->drivers->has($key) ? $this->drivers->get($key)->metadata() : $this->drivers->get('none')->metadata();
    }

    /**
     * Module commands that make sense for the current status and driver capabilities.
     *
     * @return list<array{value: string, label: string}>
     */
    public function commands(Service $service): array
    {
        $driver = $this->driver($service);

        $commands = match ($service->status) {
            ServiceStatus::Pending, ServiceStatus::Terminated => [ModuleCommandName::Create],
            ServiceStatus::Active => [ModuleCommandName::Suspend, ModuleCommandName::Terminate, ModuleCommandName::ChangePackage, ModuleCommandName::ChangePassword],
            ServiceStatus::Suspended => [ModuleCommandName::Unsuspend, ModuleCommandName::Terminate, ModuleCommandName::ChangePassword],
            default => [],
        };

        $commands = array_filter($commands, fn (ModuleCommandName $command): bool => match ($command) {
            ModuleCommandName::ChangePackage => $driver->supports(DriverMetadata::CHANGE_PACKAGE),
            ModuleCommandName::ChangePassword => $driver->supports(DriverMetadata::CHANGE_PASSWORD),
            default => true,
        });

        return array_values(array_map(fn (ModuleCommandName $command): array => ['value' => $command->value, 'label' => $command->label()], $commands));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function statusTargets(Service $service): array
    {
        return array_values(array_map(fn (ServiceStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)], $service->status->allowedTransitions()));
    }

    /**
     * Priced upgrade/downgrade targets, or the reason none are possible.
     *
     * @return array{available: bool, reason: ?string, options: list<array<string, mixed>>}
     */
    public function changeOptions(Service $service, CarbonImmutable $today): array
    {
        $priceBook = $this->catalog->priceBook();

        if ($priceBook === null) {
            return ['available' => false, 'reason' => __('Upgrades are not available right now.'), 'options' => []];
        }

        try {
            $this->quotes->assertEligible($service, $today);
        } catch (ServiceActionException $exception) {
            return ['available' => false, 'reason' => $exception->getMessage(), 'options' => []];
        }

        $targets = [];

        foreach ($priceBook->storefront($service->currency) as $group) {
            foreach ($group['products'] as $product) {
                if ($product['id'] !== $service->product_id && $priceBook->canUpgrade($service->product_id, $product['id'])) {
                    $targets[] = [$product['id'], $service->cycle];
                }
            }
        }

        foreach ($priceBook->availableCycles($service->product_id, $service->currency) as $cycle) {
            if ($cycle !== $service->cycle && $cycle !== BillingCycle::Free) {
                $targets[] = [$service->product_id, $cycle];
            }
        }

        $options = [];

        foreach ($targets as [$productId, $cycle]) {
            try {
                $options[] = $this->quotes->handle($service, $productId, $cycle, $today)->toArray();
            } catch (ServiceActionException) {
                continue;
            }
        }

        return ['available' => $options !== [], 'reason' => $options === [] ? __('There are no upgrade or downgrade options for this service.') : null, 'options' => $options];
    }
}
