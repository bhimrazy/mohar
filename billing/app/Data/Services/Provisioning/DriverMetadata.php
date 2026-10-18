<?php

namespace App\Data\Services\Provisioning;

use App\Data\Integrations\SettingField;

/**
 * Static description of a provisioning driver. Identity is on the driver (key(), name()); the capability list is
 * computed by ProvisioningDriverRegistry from the interfaces the driver implements.
 */
final readonly class DriverMetadata
{
    /**
     * @param  list<SettingField>  $productConfigFields  per-product settings (package name, plan id, ...)
     * @param  list<SettingField>  $serverFields  per-server settings beyond hostname and credentials
     * @param  UsernamePolicy|null  $usernamePolicy  the usernames the panel accepts; null when it has no rule
     * @param  int|null  $commandTimeoutSeconds  the longest one command may take, HTTP included, when it needs more than
     *                                           the host default (services.module.job_timeout_seconds). The host caps it
     *                                           (services.module.max_job_timeout_seconds).
     */
    public function __construct(
        public bool $requiresServer,
        public ?int $defaultPort = null,
        public ?int $defaultSecurePort = null,
        public array $productConfigFields = [],
        public array $serverFields = [],
        public ?UsernamePolicy $usernamePolicy = null,
        public ?int $commandTimeoutSeconds = null,
    ) {}
}
