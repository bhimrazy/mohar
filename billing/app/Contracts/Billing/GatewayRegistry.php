<?php

namespace App\Contracts\Billing;

use LogicException;

/**
 * Implemented by Billing. Extension modules register their drivers in bootModule():
 *
 *   app(GatewayRegistry::class)->register(new KhaltiDriver);
 */
interface GatewayRegistry
{
    /**
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
