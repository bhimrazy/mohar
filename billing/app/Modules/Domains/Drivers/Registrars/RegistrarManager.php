<?php

namespace App\Modules\Domains\Drivers\Registrars;

use App\Data\Integrations\SettingField;
use App\Modules\Domains\Models\RegistrarAccount;
use Closure;
use InvalidArgumentException;

/**
 * Registry of registrar drivers by key. Drivers are configured from registrar_accounts on resolve.
 *
 *   app(RegistrarManager::class)->extend('acme', AcmeRegistrar::class);
 */
class RegistrarManager
{
    /** @var array<string, class-string<Registrar>|Closure(): Registrar> */
    private array $drivers = [];

    /**
     * @param  class-string<Registrar>|Closure(): Registrar  $driver
     */
    public function extend(string $key, string|Closure $driver): void
    {
        $this->drivers[$key] = $driver;
    }

    public function has(string $key): bool
    {
        return isset($this->drivers[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->drivers);
    }

    /**
     * Display names by driver key, for selects and tables. Reads driver metadata only: the
     * driver is not configured, so no account query and no credential decryption.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        $names = [];

        foreach ($this->drivers as $key => $definition) {
            $driver = $definition instanceof Closure ? $definition() : app($definition);
            $names[$key] = $driver->name();
        }

        return $names;
    }

    /**
     * The driver's settings as typed fields (the shared SettingField schema). Reads driver metadata only.
     *
     * @return list<SettingField>
     */
    public function fields(string $key): array
    {
        if (! $this->has($key)) {
            return [];
        }

        $definition = $this->drivers[$key];
        $driver = $definition instanceof Closure ? $definition() : app($definition);

        return array_values(array_map(SettingField::fromArray(...), $driver->settingsSchema()));
    }

    /**
     * A configured driver instance. Throws for unknown keys.
     */
    public function driver(string $key): Registrar
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown registrar driver [{$key}].");
        }

        $definition = $this->drivers[$key];
        $driver = $definition instanceof Closure ? $definition() : app($definition);
        $account = RegistrarAccount::query()->where('key', $key)->first();
        $driver->configure($account->settings ?? [], $account->test_mode ?? false);

        return $driver;
    }

    /**
     * Whether the driver is registered and its account (if configured) is enabled.
     */
    public function isActive(string $key): bool
    {
        if (! $this->has($key)) {
            return false;
        }

        $account = RegistrarAccount::query()->where('key', $key)->first();

        return $account === null || $account->is_active;
    }

    /**
     * @return list<string>
     */
    public function capabilitiesOf(?string $key): array
    {
        return $key !== null && $this->has($key) ? $this->driver($key)->capabilities() : [];
    }
}
