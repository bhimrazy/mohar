<?php

namespace Mohar\Simulators;

use InvalidArgumentException;
use Mohar\Simulators\Contracts\Simulator;

/**
 * Every installed simulator, by key. The `simulate:*` commands and the end-to-end suite find simulators only here.
 */
final class SimulatorRegistry
{
    /** @var array<string, class-string<Simulator>> */
    private array $simulators = [];

    /**
     * @param  class-string<Simulator>  $simulator
     */
    public function register(string $simulator): void
    {
        if (! is_subclass_of($simulator, Simulator::class)) {
            throw new InvalidArgumentException("{$simulator} is not a simulator.");
        }

        $this->simulators[(new $simulator)->key()] = $simulator;
    }

    /**
     * @return class-string<Simulator>
     */
    public function get(string $key): string
    {
        return $this->simulators[$key] ?? throw new InvalidArgumentException("No simulator [{$key}]; installed: ".(implode(', ', $this->keys()) ?: 'none').'.');
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->simulators);
        sort($keys);

        return $keys;
    }
}
