<?php

namespace App\Modules\Billing\Drivers\Gateways;

use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Billing\GatewayRegistry;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Data\Billing\GatewayConfig;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Support\Billing\SandboxPolicy;
use Illuminate\Database\Eloquent\Collection;

/**
 * Registry of gateway drivers (Billing's own and those registered by extension modules) and their configuration.
 */
class GatewayManager implements GatewayRegistry
{
    /** @var array<string, GatewayDriver> */
    private array $drivers = [];

    public function register(GatewayDriver $driver): void
    {
        $this->drivers[$driver->key()] = $driver;
    }

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

    public function hosted(string $key): ?HostedPaymentDriver
    {
        $driver = $this->drivers[$key] ?? null;

        return $driver instanceof HostedPaymentDriver ? $driver : null;
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
     * The DTO drivers receive.
     */
    public function config(string|Gateway $gateway): GatewayConfig
    {
        $gateway = is_string($gateway) ? $this->gateway($gateway) : $gateway;
        $settings = $gateway->config ?? [];

        return new GatewayConfig(
            key: $gateway->key,
            displayName: $gateway->display_name,
            settings: $settings,
            sandbox: filter_var($settings['sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN),
            convertToCurrency: $gateway->convert_to_currency,
        );
    }

    /**
     * Whether the gateway takes test money: the simulated sandbox driver, or a provider driver whose sandbox mode
     * (the "sandbox" setting) is on.
     */
    public function isSandbox(string|Gateway $gateway): bool
    {
        $key = is_string($gateway) ? $gateway : $gateway->key;

        return str_starts_with($key, 'sandbox') || ($this->has($key) && $this->config($gateway)->sandbox);
    }

    /**
     * Active gateways clients may pay with, in display order. A gateway left in sandbox mode is never offered
     * where test money is not allowed (production), even if it was activated before the deployment changed.
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
            ->orderBy('id')
            ->get()
            ->reject(fn (Gateway $gateway): bool => ! SandboxPolicy::allowed() && $this->isSandbox($gateway))
            ->values();
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
