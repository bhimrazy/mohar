<?php

use App\Support\Services\ProvisioningDriverRegistry;
use Tests\Support\Contracts\ProvisioningDriverContract;

/** Every driver Services registers; a new built-in driver is added here and must pass the contract. */
const BUILT_IN_PROVISIONING_DRIVERS = ['none', 'log'];

foreach (BUILT_IN_PROVISIONING_DRIVERS as $key) {
    ProvisioningDriverContract::verify($key);
}

test('every registered provisioning driver runs the contract', function () {
    expect(app(ProvisioningDriverRegistry::class)->keys())->toEqualCanonicalizing(BUILT_IN_PROVISIONING_DRIVERS);
});

test('the contract has a check for every capability the registry knows', function () {
    expect(array_keys(ProvisioningDriverContract::capabilityChecks()))
        ->toEqualCanonicalizing(array_values(app(ProvisioningDriverRegistry::class)->capabilities()));
});

test('the log driver, the reference implementation, implements every capability', function () {
    $registry = app(ProvisioningDriverRegistry::class);

    expect($registry->capabilitiesOf('log'))->toEqualCanonicalizing(array_keys($registry->capabilities()))
        ->and($registry->capabilitiesOf('none'))->toEqualCanonicalizing([ProvisioningDriverRegistry::CHANGE_PACKAGE, ProvisioningDriverRegistry::CHANGE_PASSWORD]);
});
