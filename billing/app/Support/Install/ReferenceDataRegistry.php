<?php

namespace App\Support\Install;

use App\Contracts\Install\InstallsReferenceData;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * The reference-data installers of every loaded module (ADR 0012 registry shape). Modules register in bootModule():
 *
 *   app(ReferenceDataRegistry::class)->register('core_currencies', DefaultCurrencies::class, order: 10);
 *
 * `order` sorts installers that depend on others' rows (lower runs first; equal orders keep registration order).
 * Orders below 100 are reserved for Core (currencies 10, roles 20); module installers default to 100. Registration resolves nothing: installers are built through
 * the container when the install runs.
 *
 * @api
 */
class ReferenceDataRegistry
{
    private const KEY_PATTERN = '/^[a-z0-9_]{1,50}$/';

    /** @var array<string, array{item: class-string<InstallsReferenceData>|Closure(Container): InstallsReferenceData, order: int}> */
    private array $items = [];

    public function __construct(private Container $container) {}

    /**
     * @param  class-string<InstallsReferenceData>|Closure(Container): InstallsReferenceData  $installer
     */
    public function register(string $key, string|Closure $installer, bool $replace = false, int $order = 100): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid reference-data installer key [{$key}].");
        }

        if (isset($this->items[$key])) {
            if (! $replace) {
                throw new LogicException("A reference-data installer is already registered as [{$key}].");
            }

            Log::info('Reference-data installer replaced.', ['key' => $key]);
        }

        $this->items[$key] = ['item' => $installer, 'order' => $order];
    }

    public function has(string $key): bool
    {
        return isset($this->items[$key]);
    }

    public function get(string $key): InstallsReferenceData
    {
        if (! isset($this->items[$key])) {
            throw new InvalidArgumentException("No reference-data installer is registered as [{$key}].");
        }

        $item = $this->items[$key]['item'];
        $installer = $item instanceof Closure ? $item($this->container) : $this->container->make($item);

        if (! $installer instanceof InstallsReferenceData) {
            throw new LogicException("The reference-data installer registered as [{$key}] resolves to ".get_debug_type($installer).', not an '.InstallsReferenceData::class.'.');
        }

        if ($installer->key() !== $key) {
            throw new LogicException("The reference-data installer registered as [{$key}] reports the key [{$installer->key()}].");
        }

        return $installer;
    }

    /**
     * Keys in run order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $position = array_flip(array_keys($this->items));
        $keys = array_keys($this->items);

        usort($keys, fn (string $a, string $b): int => [$this->items[$a]['order'], $position[$a]] <=> [$this->items[$b]['order'], $position[$b]]);

        return $keys;
    }

    /**
     * Every installer, resolved, in run order.
     *
     * @return array<string, InstallsReferenceData>
     */
    public function all(): array
    {
        $installers = [];

        foreach ($this->keys() as $key) {
            $installers[$key] = $this->get($key);
        }

        return $installers;
    }
}
