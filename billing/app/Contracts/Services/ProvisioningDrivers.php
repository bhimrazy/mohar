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
     * Each driver's product settings. `fields` is the one integration field shape (SettingField::toArray()); fields
     * with `options_source: remote` take their options from remoteOptions().
     *
     * `config_fields` is the older shape (`type: password|text|select|...`, `options` as a list of values), kept for
     * existing consumers. @deprecated since 0.x (ADR 0009 PR B2), removed before 1.0; read `fields` instead.
     *
     * @return list<array{key: string, label: string, fields: list<array{key: string, label: string, type: string, secret: bool, required: bool, help: string|null, options: array<array-key, string>, default: string|int|bool|null, options_source: string|null}>, config_fields: list<array{key: string, label: string, type: string, required: bool, secret: bool, options?: list<string>, options_source?: string}>}>
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
