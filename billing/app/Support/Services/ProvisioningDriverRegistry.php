<?php

namespace App\Support\Services;

use App\Contracts\Integrations\IntegrationDriver;
use App\Contracts\Services\Provisioning\ChangesPackage;
use App\Contracts\Services\Provisioning\ChangesPassword;
use App\Contracts\Services\Provisioning\ListsAccounts;
use App\Contracts\Services\Provisioning\ProvidesFieldOptions;
use App\Contracts\Services\Provisioning\ProvidesServerSso;
use App\Contracts\Services\Provisioning\ProvidesServiceSso;
use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Contracts\Services\Provisioning\ReconcilesCommands;
use App\Contracts\Services\Provisioning\RenewsAccounts;
use App\Contracts\Services\Provisioning\ReportsUsage;
use App\Contracts\Services\Provisioning\TestsServerConnection;
use App\Data\Services\Provisioning\DriverMetadata;
use App\Enums\FieldType;
use App\Support\Integrations\DriverRegistry;
use LogicException;

/**
 * Every installed provisioning driver (ADR 0009 §5). Kernel-owned, so a package registers in its own boot() even
 * when the Services module is not loaded:
 *
 *   $this->app->make(ProvisioningDriverRegistry::class)->register('cpanel', CpanelDriver::class);
 *
 * @api
 *
 * @extends DriverRegistry<ProvisioningDriver>
 */
final class ProvisioningDriverRegistry extends DriverRegistry
{
    public const CHANGE_PACKAGE = 'change_package';

    public const CHANGE_PASSWORD = 'change_password';

    public const USAGE = 'usage';

    public const TEST_CONNECTION = 'test_connection';

    public const SERVICE_SSO = 'service_sso';

    public const SERVER_SSO = 'server_sso';

    public const LIST_ACCOUNTS = 'list_accounts';

    public const RENEW = 'renew';

    public const FIELD_OPTIONS = 'field_options';

    public const RECONCILE = 'reconcile';

    protected function contract(): string
    {
        return ProvisioningDriver::class;
    }

    public function capabilities(): array
    {
        return [
            self::CHANGE_PACKAGE => ChangesPackage::class,
            self::CHANGE_PASSWORD => ChangesPassword::class,
            self::USAGE => ReportsUsage::class,
            self::TEST_CONNECTION => TestsServerConnection::class,
            self::SERVICE_SSO => ProvidesServiceSso::class,
            self::SERVER_SSO => ProvidesServerSso::class,
            self::LIST_ACCOUNTS => ListsAccounts::class,
            self::RENEW => RenewsAccounts::class,
            self::FIELD_OPTIONS => ProvidesFieldOptions::class,
            self::RECONCILE => ReconcilesCommands::class,
        ];
    }

    public function metadata(string $key): DriverMetadata
    {
        return $this->get($key)->metadata();
    }

    /**
     * Only the gateway host stores certificate uploads so far (ADR 0009 Amendment (#489)).
     *
     * @param  ProvisioningDriver  $driver
     */
    protected function validate(IntegrationDriver $driver): void
    {
        $metadata = $driver->metadata();

        foreach ([...$metadata->productConfigFields, ...$metadata->serverFields] as $field) {
            if ($field->type === FieldType::Certificate) {
                throw new LogicException("The provisioning driver [{$driver->key()}] declares the certificate setting [{$field->key}], which servers and products do not support yet.");
            }
        }
    }
}
