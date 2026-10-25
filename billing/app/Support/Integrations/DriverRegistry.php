<?php

namespace App\Support\Integrations;

use App\Contracts\Integrations\IntegrationDriver;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Base of the kernel-owned driver registries (ADR 0009 §5, ADR 0012). A family registry extends it, names its core
 * interface and capability interfaces, and is bound as a singleton in AppServiceProvider, so a package can register
 * in its own boot() whether or not the consuming module is loaded:
 *
 *   $this->app->make(ProvisioningDriverRegistry::class)->register('cpanel', CpanelDriver::class);
 *
 * register() does no I/O and resolves nothing. get() builds the driver through the container each time (bind it as
 * a singleton to share one instance) and checks that it implements the family interface, reports the key it was
 * registered under and passes the family's validate() rules. Drivers hold no account state, so a resolved driver is
 * safe to reuse. has(), keys() and all() count only drivers that pass those checks: a driver that fails them is
 * reported, left out and listed by unavailable() for staff, so one broken package cannot take down the pages that list
 * every driver, and nothing new can be bound to it. A failure of the family's own checks (a LogicException) is
 * remembered for FAILURE_TTL seconds; any other failure (a constructor that failed once) is checked again next time.
 * isRegistered() answers for every registered key, for callers that must fail closed on a hidden driver.
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

    /** Seconds a driver that failed the family's checks stays hidden before it is checked again. */
    public const FAILURE_TTL = 60;

    /** @var array<string, true> keys that passed get()'s checks */
    private array $usable = [];

    /** @var array<string, array{reason: string, until: int}> keys that failed the family's checks, until a recheck */
    private array $failed = [];

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
        unset($this->usable[$key], $this->failed[$key]);
    }

    /**
     * Whether a usable driver is registered as $key (the same set keys() lists).
     */
    public function has(string $key): bool
    {
        return isset($this->drivers[$key]) && $this->usable($key);
    }

    /**
     * Whether anything is registered as $key, usable or not.
     */
    public function isRegistered(string $key): bool
    {
        return isset($this->drivers[$key]);
    }

    /**
     * Registered drivers that fail get()'s checks, with a reason staff can read.
     *
     * @return array<string, string>
     */
    public function unavailable(): array
    {
        $unavailable = [];

        foreach (array_keys($this->drivers) as $key) {
            if (! $this->usable($key)) {
                $unavailable[$key] = $this->failed[$key]['reason'] ?? __('It could not be loaded; the error log has the details.');
            }
        }

        return $unavailable;
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

        $this->validate($driver);

        return $driver;
    }

    /**
     * Keys of the drivers that pass get()'s checks, in registration order. A driver that fails them is reported and
     * left out.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values(array_filter(array_keys($this->drivers), fn (string $key): bool => $this->usable($key)));
    }

    /**
     * Family rules a resolved driver must meet; throws a LogicException naming the driver when it does not.
     *
     * @param  TDriver  $driver
     */
    protected function validate(IntegrationDriver $driver): void {}

    private function usable(string $key): bool
    {
        if (isset($this->usable[$key])) {
            return true;
        }

        if (isset($this->failed[$key]) && $this->failed[$key]['until'] > now()->getTimestamp()) {
            return false;
        }

        unset($this->failed[$key]);

        try {
            $this->get($key);
            $this->usable[$key] = true;

            return true;
        } catch (LogicException $exception) {
            report($exception);
            $this->failed[$key] = ['reason' => $exception->getMessage(), 'until' => now()->getTimestamp() + self::FAILURE_TTL];
        } catch (Throwable $exception) {
            report($exception);
        }

        return false;
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
