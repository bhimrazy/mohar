<?php

namespace App\Modules\Services\Services;

use App\Contracts\Services\Provisioning\ChangesPackage;
use App\Contracts\Services\Provisioning\ChangesPassword;
use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Enums\BillingCycle;
use App\Modules\Services\Actions\QuoteServiceChange;
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
        private ServiceDrivers $drivers,
        private QuoteServiceChange $quotes,
    ) {}

    /**
     * The service's driver (the manual driver when its own is not installed), for capability checks.
     */
    public function driver(Service $service): ProvisioningDriver
    {
        return $this->drivers->displayDriverFor($service);
    }

    /**
     * Whether the client area may show the stored password (#44): a live service with a username and a stored
     * password. Panels that sign in only by SSO store none. Reads the raw column, so nothing is decrypted to decide.
     */
    public function revealsPassword(Service $service): bool
    {
        return in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)
            && $service->username !== null
            && $service->getRawOriginal('password') !== null;
    }

    /**
     * @return array{key: string, name: string, requires_server: bool, capabilities: list<string>}
     */
    public function driverProps(Service $service): array
    {
        return $this->drivers->present($service);
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
            ModuleCommandName::ChangePackage => $driver instanceof ChangesPackage,
            ModuleCommandName::ChangePassword => $driver instanceof ChangesPassword,
            default => true,
        });

        return array_values(array_map(fn (ModuleCommandName $command): array => ['value' => $command->value, 'label' => $command->label()], $commands));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function statusTargets(Service $service): array
    {
        return array_map(fn (ServiceStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)], $service->status->allowedTransitions());
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
            return ['available' => false, 'reason' => 'Upgrades are not available right now.', 'options' => []];
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

        return ['available' => $options !== [], 'reason' => $options === [] ? 'There are no upgrade or downgrade options for this service.' : null, 'options' => $options];
    }
}
