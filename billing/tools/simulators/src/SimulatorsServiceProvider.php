<?php

namespace Mohar\Simulators;

use App\Support\Integrations\Endpoints;
use Illuminate\Support\ServiceProvider;
use Mohar\Simulators\Console\CleanCommand;
use Mohar\Simulators\Console\E2eCommand;
use Mohar\Simulators\Console\ListCommand;
use Mohar\Simulators\Console\RecordCommand;
use Mohar\Simulators\Console\ScanCommand;
use Mohar\Simulators\Console\StartCommand;
use Mohar\Simulators\Console\StopCommand;
use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Providers\Cybersource\CybersourceSimulator;
use Mohar\Simulators\Providers\Esewa\EsewaSimulator;
use Mohar\Simulators\Providers\Khalti\KhaltiSimulator;
use Mohar\Simulators\Providers\Namecheap\NamecheapSimulator;
use Mohar\Simulators\Providers\ResellerClub\ResellerClubSimulator;
use Mohar\Simulators\Providers\Stripe\StripeSimulator;
use Mohar\Simulators\Providers\Whm\WhmSimulator;
use Mohar\Simulators\Providers\Whmcs\WhmcsSimulator;

/**
 * Registered by package discovery from require-dev only (ADR 0016 §5 guarantee 1). Outside `local` and `testing` it
 * registers nothing, and every command checks again when it runs.
 */
final class SimulatorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! Endpoints::developmentEnvironment($this->app)) {
            return;
        }

        $this->app->singleton(SimulatorRegistry::class, function (): SimulatorRegistry {
            $registry = new SimulatorRegistry;

            foreach ($this->simulators() as $simulator) {
                $registry->register($simulator);
            }

            return $registry;
        });
    }

    /**
     * Simulators shipped in this package; each provider PR adds its own (S2 `whm`, S3 `stripe`, ...).
     *
     * @return list<class-string<Simulator>>
     */
    public function simulators(): array
    {
        return [WhmSimulator::class, StripeSimulator::class, KhaltiSimulator::class, EsewaSimulator::class, CybersourceSimulator::class, ResellerClubSimulator::class, NamecheapSimulator::class, WhmcsSimulator::class];
    }

    public function boot(): void
    {
        if (! Endpoints::developmentEnvironment($this->app) || ! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([ListCommand::class, StartCommand::class, StopCommand::class, CleanCommand::class, RecordCommand::class, ScanCommand::class, E2eCommand::class]);
    }
}
