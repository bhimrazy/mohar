<?php

use App\Support\Domains\RegistrarDriverRegistry;
use Tests\Support\Contracts\RegistrarDriverContract;

/**
 * Every registrar driver shipped with the Domains module passes the shared contract (registrars.md §7). A new
 * built-in driver is added here.
 */
$builtInDrivers = ['log' => null];

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
