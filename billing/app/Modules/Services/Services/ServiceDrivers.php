<?php

namespace App\Modules\Services\Services;

use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\Catalog;
use App\Modules\Services\Support\ServiceSettings;
use App\Support\Services\ProvisioningDriverRegistry;
use Throwable;

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
        $key = $this->keyFor($service);

        // An installed driver that failed the host's checks is named as unavailable, not shown as manual setup.
        if ($this->drivers->isRegistered($key) && ! $this->drivers->has($key)) {
            return ['key' => $key, 'name' => __(':driver (unavailable)', ['driver' => $key]), 'requires_server' => $service->server_id !== null, 'capabilities' => []];
        }

        $driver = $this->displayDriverFor($service);

        return [
            'key' => $driver->key(),
            'name' => $driver->name(),
            'requires_server' => $driver->metadata()->requiresServer,
            'capabilities' => $this->drivers->capabilitiesOf($driver->key()),
        ];
    }

    /**
     * Run time allowed to one command job for the service (DriverMetadata::$commandTimeoutSeconds within the cap). A
     * driver that cannot be resolved gets the host default: the command itself then fails, and nothing before it
     * (automation loops, dispatching) does.
     */
    public function jobTimeoutFor(Service|int $service): int
    {
        $service = $service instanceof Service ? $service : Service::withTrashed()->with('server')->find($service);

        try {
            $key = $service === null ? null : $this->keyFor($service);
            $seconds = $key !== null && $this->drivers->has($key) ? $this->drivers->metadata($key)->commandTimeoutSeconds : null;
        } catch (Throwable $exception) {
            report($exception);
            $seconds = null;
        }

        return $this->settings->jobTimeoutSeconds($seconds);
    }
}
