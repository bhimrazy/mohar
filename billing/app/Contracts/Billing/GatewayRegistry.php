<?php

namespace App\Contracts\Billing;

use LogicException;

/**
 * Implemented by Billing, which reads the drivers from App\Support\Billing\GatewayDriverRegistry. Register drivers
 * there, by key and class, from your provider's boot() (ADR 0012, ADR 0030 M3):
 *
 *   $this->app->make(GatewayDriverRegistry::class)->register('khalti', KhaltiDriver::class);
 */
interface GatewayRegistry
{
    /**
     * @deprecated since 0.2.0, removed in 1.0.0; use GatewayDriverRegistry::register($key, $class). Needs Billing to
     * be loaded, and replaces a key registered before.
     *
     * @throws LogicException when the key is not 1 to 50 of [a-z0-9_], or is reserved (`import`, #263)
     */
    public function register(GatewayDriver $driver): void;

    public function has(string $key): bool;

    public function driver(string $key): GatewayDriver;

    /**
     * @return array<string, GatewayDriver>
     */
    public function drivers(): array;
}
