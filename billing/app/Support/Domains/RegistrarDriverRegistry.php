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
    public const AVAILABILITY = 'availability';

    public const SUGGESTIONS = 'suggestions';

    public const REGISTER = 'register';

    public const TRANSFER = 'transfer';

    public const RENEW = 'renew';

    public const RESTORE = 'restore';

    public const INFO = 'info';

    public const NAMESERVERS = 'nameservers';

    public const LOCK = 'lock';

    public const EPP = 'epp';

    public const CONTACTS = 'contacts';

    public const ID_PROTECTION = 'id_protection';

    public const SYNC = 'sync';

    public const TRANSFER_SYNC = 'transfer_sync';

    public const RECONCILE = 'reconcile';

    public const TEST_CONNECTION = 'test_connection';

    protected function contract(): string
    {
        return RegistrarDriver::class;
    }

    public function capabilities(): array
    {
        return [
            self::AVAILABILITY => ChecksAvailability::class,
            self::SUGGESTIONS => SuggestsNames::class,
            self::REGISTER => RegistrarDriver::class,
            self::TRANSFER => TransfersDomains::class,
            self::RENEW => RegistrarDriver::class,
            self::RESTORE => RestoresDomains::class,
            self::INFO => RegistrarDriver::class,
            self::NAMESERVERS => ManagesNameservers::class,
            self::LOCK => ManagesLock::class,
            self::EPP => ProvidesEppCode::class,
            self::CONTACTS => ManagesContacts::class,
            self::ID_PROTECTION => ManagesIdProtection::class,
            self::SYNC => SyncsDomains::class,
            self::TRANSFER_SYNC => TransfersDomains::class,
            self::RECONCILE => ReconcilesSubmissions::class,
            self::TEST_CONNECTION => TestsRegistrarConnection::class,
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
