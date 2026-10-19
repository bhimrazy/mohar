<?php

namespace Mohar\Simulators\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use InvalidArgumentException;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Scenario;

#[Signature('simulate:stop {scenario : the scenario id simulate:start printed} {--keep : keep its state, certificates and logs}')]
#[Description('Stop a running simulator scenario')]
final class StopCommand extends SimulatorCommand
{
    protected function perform(): int
    {
        $id = basename((string) $this->argument('scenario'));

        try {
            Processes::stop(Scenario::load(Scenario::root().'/'.$id), (bool) $this->option('keep'));
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Scenario {$id} stopped.");

        return self::SUCCESS;
    }
}
