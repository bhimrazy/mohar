<?php

namespace Mohar\Simulators\Console;

use App\Support\Integrations\Endpoints;
use Illuminate\Console\Command;

/**
 * Base of the `simulate:*` commands: each refuses to run outside `local` and `testing`, whatever registered it.
 */
abstract class SimulatorCommand extends Command
{
    public function handle(): int
    {
        if (! Endpoints::developmentEnvironment($this->laravel)) {
            $this->components->error('Simulators run only when APP_ENV is local or testing.');

            return self::FAILURE;
        }

        return $this->perform();
    }

    abstract protected function perform(): int;

    /**
     * A required string argument; the console definition guarantees presence, so anything else is a programming error.
     */
    protected function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("The {$name} argument must be a non-empty string.");
        }

        return $value;
    }
}
