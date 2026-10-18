<?php

use App\Support\Services\ProvisioningDriverRegistry;
use Tests\Support\Contracts\ProvisioningDriverContract;
use Tests\Support\Cpanel\Whm;

/** Every driver Services registers; a new built-in driver is added here and must pass the contract. */
const BUILT_IN_PROVISIONING_DRIVERS = ['none', 'log', 'cpanel'];

ProvisioningDriverContract::verify('none');
ProvisioningDriverContract::verify('log');
// A placeholder pin is refused (fail closed) and a placeholder flag reads as off: run with CA verification and SSO on.
ProvisioningDriverContract::verify('cpanel', arrange: fn () => Whm::contract(), serverSettings: ['spki_pin' => '', 'sso_enabled' => true]);

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
