<?php

namespace App\Modules\Services\Services;

use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\Catalog;
use App\Modules\Services\Support\ServiceSettings;
use App\Support\Services\ProvisioningDriverRegistry;

/**
 * Which provisioning driver a service uses (the product's driver, else its server's, else manual) and the limits
 * that follow from it.
 */
class ServiceDrivers
{
    public function __construct(
        private ProvisioningDriverRegistry $drivers,
        private Catalog $catalog,
        private ServiceSettings $settings,
    ) {}

    public function keyFor(Service $service): string
    {
        return $this->catalog->product($service->product_id)->provisioningDriver ?? $service->server->driver ?? 'none';
    }

    /**
     * The service's driver, or the manual driver when its driver is not installed (for screens; commands fail
     * loudly instead).
     */
    public function displayDriverFor(Service $service): ProvisioningDriver
    {
        $key = $this->keyFor($service);

        return $this->drivers->get($this->drivers->has($key) ? $key : 'none');
    }

    /**
     * @return array{key: string, name: string, requires_server: bool, capabilities: list<string>}
     */
    public function present(Service $service): array
    {
        $driver = $this->displayDriverFor($service);

        return [
            'key' => $driver->key(),
            'name' => $driver->name(),
            'requires_server' => $driver->metadata()->requiresServer,
            'capabilities' => $this->drivers->capabilitiesOf($driver->key()),
        ];
    }

    /**
     * Run time allowed to one command job for the service (DriverMetadata::$commandTimeoutSeconds within the cap).
     */
    public function jobTimeoutFor(int $serviceId): int
    {
        $service = Service::withTrashed()->with('server')->find($serviceId);
        $key = $service === null ? null : $this->keyFor($service);

        return $this->settings->jobTimeoutSeconds($key !== null && $this->drivers->has($key) ? $this->drivers->metadata($key)->commandTimeoutSeconds : null);
    }
}
