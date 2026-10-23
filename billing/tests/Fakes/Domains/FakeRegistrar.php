<?php

namespace Tests\Fakes\Domains;

use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Contracts\Domains\Registrar\StandaloneRegistrar;
use App\Contracts\Integrations\IntegrationDriver;
use App\Data\Domains\Registrar\RegistrationRequest;
use App\Data\Integrations\SettingField;
use App\Support\Domains\RegistrarDriverRegistry;
use Closure;
use LogicException;
use Tests\Fakes\Domains\Registrars\CoreOnlyRegistrar;
use Tests\Fakes\Domains\Registrars\FullRegistrar;
use Tests\Fakes\Domains\Registrars\FullWithoutAttributesRegistrar;
use Tests\Fakes\Domains\Registrars\LinkingRegistrar;
use Tests\Fakes\Domains\Registrars\NameserverEppRegistrar;
use Tests\Fakes\Domains\Registrars\NameserverRegistrar;
use Tests\Fakes\Domains\Registrars\NameserverSyncingRegistrar;
use Tests\Fakes\Domains\Registrars\ReconcilingRegistrar;
use Tests\Fakes\Domains\Registrars\SyncingRegistrar;
use Throwable;

/**
 * Scriptable registrar: set the next result (or exception) per command and inspect calls. Capabilities come from
 * interfaces (ADR 0012), so each resolve hands the host the fixture class (tests/Fakes/Domains/Registrars) that
 * implements exactly the capability interfaces named in $capabilities; a test can change them at any time. The core
 * capabilities (`register`, `renew`, `info`) cannot be removed.
 */
class FakeRegistrar
{
    /** @var list<string> every capability except `reconcile` (it replaces the info check) and `link_order`, which tests opt into */
    public array $capabilities = [];

    /** @var list<array{command: string, domain: string|null}> */
    public array $calls = [];

    /** @var array<string, mixed> command => result object, Throwable, or Closure returning either (runs during the call) */
    public array $results = [];

    public function __construct()
    {
        $this->capabilities = array_values(array_diff(array_keys(app(RegistrarDriverRegistry::class)->capabilities()), ['reconcile', 'link_order']));
    }

    /** @var array<string, list<SettingField>> extension => the fields a driver with `tld_attributes` declares */
    public array $tldFields = [];

    /** @var list<RegistrationRequest> every register/transfer request received */
    public array $requests = [];

    /** @var list<SettingField> the replaced driver's settings, if it replaced one */
    public array $settingsFields = [];

    public static function install(string $key = 'fake'): self
    {
        $fake = new self;
        $registry = app(RegistrarDriverRegistry::class);
        // Standing in for a real driver keeps its settings, so the host masks that driver's secrets as it would.
        $fake->settingsFields = $registry->has($key) ? $registry->fields($key) : [];
        $registry->register($key, fn (): RegistrarDriver => $fake->driver($key), replace: true);

        return $fake;
    }

    public function will(string $command, mixed $result): self
    {
        $this->results[$command] = $result;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function commands(): array
    {
        return array_column($this->calls, 'command');
    }

    /**
     * The fixture class implementing exactly the capability interfaces of $capabilities. `register`, `renew` and
     * `info` are core: every fake has them, whatever $capabilities says.
     */
    public function driver(string $key): FakeRegistrarDriver
    {
        $interfaces = array_values(array_unique(array_intersect_key(app(RegistrarDriverRegistry::class)->capabilities(), array_flip($this->capabilities))));
        $interfaces = array_values(array_diff($interfaces, [RegistrarDriver::class]));
        sort($interfaces);
        $class = self::fixtures()[implode(',', $interfaces)] ?? throw new LogicException('No fake registrar implements ['.implode(', ', $interfaces).']; add a fixture class under tests/Fakes/Domains/Registrars and map it in FakeRegistrar::fixtures().');

        return new $class($this, $key);
    }

    /**
     * Fixture class by its sorted capability interfaces (a closed map: PHP cannot add interfaces at run time).
     *
     * @return array<string, class-string<FakeRegistrarDriver>>
     */
    private static function fixtures(): array
    {
        $fixtures = [];

        foreach ([CoreOnlyRegistrar::class, SyncingRegistrar::class, NameserverRegistrar::class, NameserverEppRegistrar::class, NameserverSyncingRegistrar::class, FullRegistrar::class, FullWithoutAttributesRegistrar::class, ReconcilingRegistrar::class, LinkingRegistrar::class] as $class) {
            $interfaces = array_values(array_diff((array) class_implements($class), [RegistrarDriver::class, IntegrationDriver::class, StandaloneRegistrar::class]));
            sort($interfaces);
            $fixtures[implode(',', $interfaces)] = $class;
        }

        return $fixtures;
    }

    /**
     * @template T
     *
     * @param  T  $default
     * @return T
     */
    public function record(string $command, ?string $domain, mixed $default): mixed
    {
        $this->calls[] = ['command' => $command, 'domain' => $domain];
        $result = $this->results[$command] ?? $default;

        if ($result instanceof Closure) {
            $result = $result();
        }

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
