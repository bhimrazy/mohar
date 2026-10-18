<?php

namespace App\Modules\Services\Drivers\Provisioning;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Available provisioning drivers. Register more in a provider:
 *
 *   app(DriverRegistry::class)->register('cpanel', CpanelDriver::class);
 */
class DriverRegistry
{
    /** @var array<string, class-string<ProvisioningDriver>> */
    private array $drivers = [];

    public function __construct(private Container $container) {}

    /**
     * @param  class-string<ProvisioningDriver>  $driver
     */
    public function register(string $key, string $driver): void
    {
        $this->drivers[$key] = $driver;
    }

    public function has(string $key): bool
    {
        return isset($this->drivers[$key]);
    }

    public function get(string $key): ProvisioningDriver
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown provisioning driver [{$key}].");
        }

        return $this->container->make($this->drivers[$key]);
    }

    /**
     * @return array<string, DriverMetadata>
     */
    public function all(): array
    {
        return array_map(fn (string $key): DriverMetadata => $this->get($key)->metadata(), array_combine(array_keys($this->drivers), array_keys($this->drivers)));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function options(): array
    {
        return array_values(array_map(fn (DriverMetadata $metadata): array => ['value' => $metadata->key, 'label' => $metadata->name], $this->all()));
    }
}
