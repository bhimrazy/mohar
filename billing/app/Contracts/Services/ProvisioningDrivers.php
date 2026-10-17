<?php

namespace App\Contracts\Services;

/**
 * Provisioning drivers installed in the Services module (for the Catalog product editor and validation).
 * Core binds a null implementation (no drivers) when Services is not installed.
 */
interface ProvisioningDrivers
{
    /**
     * @return list<array{key: string, label: string, config_fields: list<array{key: string, label: string, type: string, required: bool, secret: bool, options?: list<string>}>}>
     */
    public function all(): array;

    public function exists(string $key): bool;
}
