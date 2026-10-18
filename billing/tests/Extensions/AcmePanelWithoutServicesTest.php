<?php

use App\Contracts\Services\ProvisioningDrivers;
use App\Support\Services\ProvisioningDriverRegistry;
use Tests\Fixtures\Extensions\AcmePanel\AcmePanelDriver;
use Tests\Fixtures\Extensions\AcmePanel\AcmePanelServiceProvider;
use Tests\TestCase;

beforeAll(fn () => TestCase::$onlyModules = 'Core');
afterAll(fn () => TestCase::$onlyModules = null);

beforeEach(fn () => $this->app->register(AcmePanelServiceProvider::class));

test('a package registers a provisioning driver when only Core is loaded', function () {
    $registry = app(ProvisioningDriverRegistry::class);

    expect(class_exists('App\Modules\Services\ServicesServiceProvider') && app()->providerIsLoaded('App\Modules\Services\ServicesServiceProvider'))->toBeFalse()
        ->and($registry->keys())->toBe(['acme_panel'])
        ->and($registry->get('acme_panel'))->toBeInstanceOf(AcmePanelDriver::class)
        ->and($registry->capabilitiesOf('acme_panel'))->toEqualCanonicalizing([ProvisioningDriverRegistry::CHANGE_PASSWORD, ProvisioningDriverRegistry::TEST_CONNECTION])
        ->and(app(ProvisioningDrivers::class)->all())->toBe([]);
});
