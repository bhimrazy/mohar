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
}
