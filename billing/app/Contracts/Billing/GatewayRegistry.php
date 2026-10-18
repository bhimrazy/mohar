<?php

namespace App\Contracts\Billing;

/**
 * Implemented by Billing. Extension modules register their drivers in bootModule():
 *
 *   app(GatewayRegistry::class)->register(new KhaltiDriver);
 */
interface GatewayRegistry
{
    public function register(GatewayDriver $driver): void;

    public function has(string $key): bool;

    public function driver(string $key): GatewayDriver;

    /**
     * @return array<string, GatewayDriver>
     */
    public function drivers(): array;
}
