<?php

use App\Support\Services\ProvisioningDriverRegistry;
use Tests\Support\Contracts\ProvisioningDriverContract;
use Tests\Support\Cpanel\Whm;

/**
 * Every driver Services registers, with how its remote system is faked and any server settings whose placeholder
 * would be invalid. A new built-in driver is added here and must pass the contract.
 *
 * cpanel: a placeholder pin is refused (fail closed) and a placeholder flag reads as off, so it runs with CA
 * verification and SSO on.
 */
$builtInDrivers = [
    'none' => [null, []],
    'log' => [null, []],
    'cpanel' => [fn () => Whm::contract(), ['spki_pin' => '', 'sso_enabled' => true]],
];

foreach ($builtInDrivers as $key => [$arrange, $serverSettings]) {
    ProvisioningDriverContract::verify($key, $arrange, $serverSettings);
}

test('every registered provisioning driver runs the contract', function () use ($builtInDrivers) {
    expect(app(ProvisioningDriverRegistry::class)->keys())->toEqualCanonicalizing(array_keys($builtInDrivers));
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
