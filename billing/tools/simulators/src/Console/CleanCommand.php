<?php

namespace Mohar\Simulators\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Mohar\Simulators\Kernel\Processes;

#[Signature('simulate:clean')]
#[Description('Stop every simulator scenario and remove its temporary files')]
final class CleanCommand extends SimulatorCommand
{
    protected function perform(): int
    {
        $removed = Processes::clean();
        $this->components->info(count($removed).' scenario(s) removed.');

        return self::SUCCESS;
    }
}
