<?php

namespace App\Modules\Services\Services;

use App\Contracts\Services\ProvisioningDrivers;
use App\Data\Services\RemoteOptions;
use App\Modules\Services\Drivers\Provisioning\DriverMetadata;
use App\Modules\Services\Drivers\Provisioning\DriverRegistry;

/**
 * Exposes the installed provisioning drivers and their product config fields to other modules.
 */
class RegistryProvisioningDrivers implements ProvisioningDrivers
{
    public function __construct(private DriverRegistry $drivers) {}

    public function all(): array
    {
        return array_values(array_map(fn (DriverMetadata $metadata): array => [
            'key' => $metadata->key,
            'label' => $metadata->name,
            'config_fields' => $metadata->productConfigFields,
        ], $this->drivers->all()));
    }

    public function exists(string $key): bool
    {
        return $this->drivers->has($key);
    }

    /**
     * Work in progress (ADR 0009 PR B): answers once drivers move to the kernel registry.
     */
    public function remoteOptions(string $driverKey, string $fieldKey, array $serverIds, bool $refresh = false): RemoteOptions
    {
        return new RemoteOptions;
    }
}
