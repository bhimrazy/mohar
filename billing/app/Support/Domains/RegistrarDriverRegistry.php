<?php

namespace App\Support\Domains;

use App\Contracts\Domains\Registrar\ChecksAvailability;
use App\Contracts\Domains\Registrar\ManagesContacts;
use App\Contracts\Domains\Registrar\ManagesIdProtection;
use App\Contracts\Domains\Registrar\ManagesLock;
use App\Contracts\Domains\Registrar\ManagesNameservers;
use App\Contracts\Domains\Registrar\ProvidesEppCode;
use App\Contracts\Domains\Registrar\ReconcilesSubmissions;
use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Contracts\Domains\Registrar\RestoresDomains;
use App\Contracts\Domains\Registrar\SuggestsNames;
use App\Contracts\Domains\Registrar\SyncsDomains;
use App\Contracts\Domains\Registrar\TestsRegistrarConnection;
use App\Contracts\Domains\Registrar\TransfersDomains;
use App\Data\Integrations\SettingField;
use App\Support\Integrations\DriverRegistry;

/**
 * Every installed registrar driver (ADR 0009 §5). Kernel-owned, so a package registers in its own boot() even when
 * the Domains module is not loaded:
 *
 *   $this->app->make(RegistrarDriverRegistry::class)->register('resellerclub', ResellerClubDriver::class);
 *
 * Capability names are the strings the admin shows and the host checks; `register`, `renew` and `info` belong to
 * the core interface, so every driver has them. `transfer` and `transfer_sync` come together.
 *
 * @api
 *
 * @extends DriverRegistry<RegistrarDriver>
 */
final class RegistrarDriverRegistry extends DriverRegistry
{
    protected function contract(): string
    {
        return RegistrarDriver::class;
    }

    public function capabilities(): array
    {
        return [
            'availability' => ChecksAvailability::class,
            'suggestions' => SuggestsNames::class,
            'register' => RegistrarDriver::class,
            'transfer' => TransfersDomains::class,
            'renew' => RegistrarDriver::class,
            'restore' => RestoresDomains::class,
            'info' => RegistrarDriver::class,
            'nameservers' => ManagesNameservers::class,
            'lock' => ManagesLock::class,
            'epp' => ProvidesEppCode::class,
            'contacts' => ManagesContacts::class,
            'id_protection' => ManagesIdProtection::class,
            'sync' => SyncsDomains::class,
            'transfer_sync' => TransfersDomains::class,
            'reconcile' => ReconcilesSubmissions::class,
            'test_connection' => TestsRegistrarConnection::class,
        ];
    }

    /**
     * Whether the driver registered as $key implements $capability (false for an unknown key or capability).
     */
    public function supports(string $key, string $capability): bool
    {
        return $this->has($key) && in_array($capability, $this->capabilitiesOf($key), true);
    }

    /**
     * The driver's account settings.
     *
     * @return list<SettingField>
     */
    public function fields(string $key): array
    {
        return $this->get($key)->settingsFields();
    }
}
