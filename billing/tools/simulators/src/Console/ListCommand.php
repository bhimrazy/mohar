<?php

namespace Mohar\Simulators\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Mohar\Simulators\SimulatorRegistry;

#[Signature('simulate:list')]
#[Description('List the installed provider simulators and the API reference each follows')]
final class ListCommand extends SimulatorCommand
{
    protected function perform(): int
    {
        $registry = $this->laravel->make(SimulatorRegistry::class);
        $rows = [];

        foreach ($registry->keys() as $key) {
            $simulator = new ($registry->get($key));
            $rows[] = [$key, $simulator->reference()['url'], $simulator->reference()['version'], implode(', ', $simulator->operations())];
        }

        $rows === [] ? $this->components->info('No simulators are installed yet.') : $this->table(['Key', 'Reference', 'Version', 'Operations'], $rows);

        return self::SUCCESS;
    }
}
