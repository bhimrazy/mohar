<?php

use App\Contracts\Domains\Registrar\RegistrarRefs;
use App\Support\Domains\RegistrarDriverRegistry;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Domains\InMemoryRegistrarRefs;
use Tests\Support\Contracts\RegistrarDriverContract;

/**
 * Every registrar driver shipped with the Domains module passes the shared contract (registrars.md §7). A new
 * built-in driver is added here.
 */
$builtInDrivers = [
    'log' => null,
    // ResellerClub answers every call with its error envelope here; its scenarios run against the simulator
    // (tools/simulators/tests/ResellerClub). The contract account has no reseller id, so calls are refused before HTTP.
    'resellerclub' => function (): void {
        app()->instance(RegistrarRefs::class, new InMemoryRegistrarRefs);
        Http::fake(['*' => Http::response(['status' => 'ERROR', 'message' => 'Refused by the contract fake.'], 500)]);
    },
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
