<?php

namespace Mohar\Simulators\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\SimulatorRegistry;

#[Signature('simulate:start {provider : a key from simulate:list}')]
#[Description('Start a provider simulator on free local ports and print what the app needs to use it')]
final class StartCommand extends SimulatorCommand
{
    protected function perform(): int
    {
        $simulator = $this->laravel->make(SimulatorRegistry::class)->get((string) $this->argument('provider'));
        $scenario = Processes::start($simulator);
        $configuration = $scenario->simulator()->appConfiguration($scenario);

        $this->components->info("Scenario {$scenario->id()} is running.");
        $this->components->twoColumnDetail('API', $scenario->apiUrl());
        $this->components->twoColumnDetail('Control plane', $scenario->controlUrl().'/__sim (header X-Sim-Token: '.$scenario->token().')');
        $this->components->twoColumnDetail('CA bundle', $scenario->ca()->bundlePath());
        $this->line('');

        foreach (['INTEGRATION_CA_BUNDLE' => $scenario->ca()->bundlePath(), ...$configuration['env']] as $name => $value) {
            $this->line("{$name}={$value}");
        }

        foreach ($configuration['notes'] as $note) {
            $this->components->bulletList([$note]);
        }

        $this->line('');
        $this->line("Stop it with: php artisan simulate:stop {$scenario->id()}");

        return self::SUCCESS;
    }
}
