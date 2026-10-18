<?php

namespace App\Contracts\Services;

use App\Data\Services\RemoteOptions;

/**
 * Provisioning drivers installed in the Services module (for the Catalog product editor and validation).
 * Core binds a null implementation (no drivers) when Services is not installed. Service contract (ADR 0010); the
 * extension SPI is App\Support\Services\ProvisioningDriverRegistry.
 */
interface ProvisioningDrivers
{
    /**
     * `options_source` is present on fields whose options come from the remote system (remoteOptions()).
     *
     * @return list<array{key: string, label: string, config_fields: list<array{key: string, label: string, type: string, required: bool, secret: bool, options?: list<string>, options_source?: string}>}>
     */
    public function all(): array;

    public function exists(string $key): bool;

    /**
     * Options for a product config field with an options source (e.g. the packages a panel offers), asked from the
     * given servers. Answers are cached for a few minutes; $refresh asks again. Never throws for an unreachable
     * server: it is listed in RemoteOptions::$unreachable.
     *
     * @param  list<int>  $serverIds
     */
    public function remoteOptions(string $driverKey, string $fieldKey, array $serverIds, bool $refresh = false): RemoteOptions;
}
