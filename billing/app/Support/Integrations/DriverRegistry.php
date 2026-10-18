<?php

namespace App\Support\Integrations;

use App\Contracts\Integrations\IntegrationDriver;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * Base of the kernel-owned driver registries (ADR 0009 §5, ADR 0012). A family registry extends it, names its core
 * interface and capability interfaces, and is bound as a singleton in AppServiceProvider, so a package can register
 * in its own boot() whether or not the consuming module is loaded:
 *
 *   $this->app->make(ProvisioningDriverRegistry::class)->register('cpanel', CpanelDriver::class);
 *
 * register() does no I/O and resolves nothing. get() builds the driver through the container each time (bind it as
 * a singleton to share one instance) and checks that it implements the family interface and reports the key it was
 * registered under. Drivers hold no account state, so a resolved driver is safe to reuse.
 *
 * @api
 *
 * @template TDriver of IntegrationDriver
 */
abstract class DriverRegistry
{
    private const KEY_PATTERN = '/^[a-z0-9_]{1,50}$/';

    /** @var array<string, class-string<TDriver>|Closure(Container): TDriver> */
    private array $drivers = [];

    public function __construct(protected Container $container) {}

    /**
     * The family's core interface.
     *
     * @return class-string<TDriver>
     */
    abstract protected function contract(): string;

    /**
     * Capability name (as the admin UI shows it) => capability interface.
     *
     * @return array<string, class-string>
     */
    abstract public function capabilities(): array;

    /**
     * @param  class-string<TDriver>|Closure(Container): TDriver  $driver
     */
    public function register(string $key, string|Closure $driver, bool $replace = false): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid driver key [{$key}]: use 1 to 50 lowercase letters, digits or underscores.");
        }

        if (isset($this->drivers[$key])) {
            if (! $replace) {
                throw new LogicException("A driver is already registered as [{$key}] in ".static::class.'; pass replace: true to override it.');
            }

            Log::info('Integration driver replaced.', ['registry' => static::class, 'key' => $key]);
        }

        $this->drivers[$key] = $driver;
    }

    public function has(string $key): bool
    {
        return isset($this->drivers[$key]);
    }

    /**
     * @return TDriver
     */
    public function get(string $key): IntegrationDriver
    {
        if (! isset($this->drivers[$key])) {
            throw new InvalidArgumentException("No driver is registered as [{$key}] in ".static::class.'.');
        }

        $definition = $this->drivers[$key];
        $driver = $definition instanceof Closure ? $definition($this->container) : $this->container->make($definition);
        $contract = $this->contract();

        if (! $driver instanceof $contract) {
            throw new LogicException("The driver registered as [{$key}] resolves to ".get_debug_type($driver).", which does not implement {$contract}.");
        }

        if ($driver->key() !== $key) {
            throw new LogicException("The driver registered as [{$key}] reports the key [{$driver->key()}].");
        }

        return $driver;
    }

    /**
     * Registered keys, in registration order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->drivers);
    }

    /**
     * Every driver, resolved, by key.
     *
     * @return array<string, TDriver>
     */
    public function all(): array
    {
        $drivers = [];

        foreach ($this->keys() as $key) {
            $drivers[$key] = $this->get($key);
        }

        return $drivers;
    }

    /**
     * The capability names a driver implements, computed from its interfaces (never declared by hand, ADR 0012).
     *
     * @return list<string>
     */
    public function capabilitiesOf(string $key): array
    {
        $driver = $this->get($key);

        return array_keys(array_filter($this->capabilities(), fn (string $interface): bool => $driver instanceof $interface));
    }
}
