<?php

namespace App\Modules\Billing\Drivers\Gateways;

use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use Illuminate\Database\Eloquent\Collection;

/**
 * Registry of gateway drivers and their staff configuration.
 */
class GatewayManager
{
    /** @var array<string, GatewayDriver> */
    private array $drivers = [];

    public function register(GatewayDriver $driver): void
    {
        $this->drivers[$driver->key()] = $driver;
    }

    /**
     * @return array<string, GatewayDriver>
     */
    public function drivers(): array
    {
        return $this->drivers;
    }

    public function has(string $key): bool
    {
        return isset($this->drivers[$key]);
    }

    public function driver(string $key): GatewayDriver
    {
        return $this->drivers[$key] ?? throw new BillingException(__('Unknown payment gateway [:key].', ['key' => $key]));
    }

    /**
     * Configuration row for a driver (unsaved default when staff haven't configured it yet).
     */
    public function gateway(string $key): Gateway
    {
        $driver = $this->driver($key);

        return Gateway::query()->firstOrNew(['key' => $key], [
            'display_name' => $driver->name(),
            'is_active' => false,
            'show_on_order_form' => $key !== 'manual',
            'sort_order' => array_search($key, array_keys($this->drivers), true) ?: 0,
        ]);
    }

    /**
     * Active gateways clients may pay with, in display order.
     *
     * @return Collection<int, Gateway>
     */
    public function clientGateways(): Collection
    {
        return Gateway::query()
            ->where('is_active', true)
            ->where('show_on_order_form', true)
            ->whereIn('key', array_keys($this->drivers))
            ->orderBy('sort_order')
            ->get();
    }

    public function isActive(string $key): bool
    {
        return $this->has($key) && Gateway::query()->where('key', $key)->where('is_active', true)->exists();
    }

    public function supports(string $key, string $capability): bool
    {
        return $this->has($key) && $this->driver($key)->supports($capability);
    }
}
