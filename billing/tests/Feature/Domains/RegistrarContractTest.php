<?php

use App\Contracts\Domains\Registrar\RegistrarRefs;
use App\Support\Domains\RegistrarDriverRegistry;
use Tests\Fakes\Domains\InMemoryRegistrarRefs;
use Tests\Support\Contracts\RegistrarDriverContract;

/**
 * Every registrar driver shipped with the Domains module passes the shared contract (registrars.md §7). A new
 * built-in driver is added here.
 */
$builtInDrivers = [
    'log' => null,
    // The contract account has no ResellerClub reseller id, so every call is refused before any HTTP: for this driver
    // the contract proves the refusal paths only. Its working scenarios run against the simulator
    // (tools/simulators/tests/ResellerClub).
    'resellerclub' => function (): void {
        app()->instance(RegistrarRefs::class, new InMemoryRegistrarRefs);
    },
    // The contract account has no API user or client IP, so every call is refused before any HTTP; its working
    // scenarios run against the simulator (tools/simulators/tests/Namecheap) and the fixtures (NamecheapDriverTest).
    'namecheap' => null,
];

foreach ($builtInDrivers as $key => $arrange) {
    RegistrarDriverContract::verify($key, $arrange);
    RegistrarDriverContract::verifyHost($key, $arrange);
}

test('every registered registrar driver runs the contract', function () use ($builtInDrivers) {
    expect(app(RegistrarDriverRegistry::class)->keys())->toEqualCanonicalizing(array_keys($builtInDrivers));
});

test('the log driver, the reference implementation, implements every capability', function () {
    $registry = app(RegistrarDriverRegistry::class);

    expect($registry->capabilitiesOf('log'))->toEqualCanonicalizing(array_keys($registry->capabilities()));
});
