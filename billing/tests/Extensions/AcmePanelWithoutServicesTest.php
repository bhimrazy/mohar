<?php

use App\Contracts\Services\ProvisioningDrivers;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Extensions\AcmePanel\AcmePanelDriver;
use Tests\Fixtures\Extensions\AcmePanel\AcmePanelServiceProvider;
use Tests\Fixtures\Extensions\AcmePanel\AcmeRegistrarDriver;
use Tests\Support\Contracts\RegistrarDriverContract;
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

test('a package registers a registrar driver when only Core is loaded', function () {
    $registry = app(RegistrarDriverRegistry::class);

    expect(app()->providerIsLoaded('App\Modules\Domains\DomainsServiceProvider'))->toBeFalse()
        ->and($registry->keys())->toBe(['acme_registrar'])
        ->and($registry->get('acme_registrar'))->toBeInstanceOf(AcmeRegistrarDriver::class)
        ->and($registry->capabilitiesOf('acme_registrar'))->toEqualCanonicalizing(['register', 'renew', 'info', 'availability', 'nameservers', 'test_connection']);
});

test('the registrar contract has calls for every capability interface the registry knows', function () {
    expect(array_keys(RegistrarDriverContract::calls()))
        ->toEqualCanonicalizing(array_values(array_unique(app(RegistrarDriverRegistry::class)->capabilities())));
});

RegistrarDriverContract::verify('acme_registrar', arrange: fn () => Http::fake([
    'sandbox.acme-registrar.test/v1/check*' => Http::response(['results' => ['.com' => 'free']]),
    'sandbox.acme-registrar.test/v1/domains/contract-test.com' => Http::response(['id' => 77, 'status' => 'active', 'expires' => '2028-01-31', 'nameservers' => ['ns1.contract.test']]),
    'sandbox.acme-registrar.test/v1/account' => Http::response(['balance' => '120.00']),
    'sandbox.acme-registrar.test/v1/*' => Http::response(['id' => 77, 'state' => 'done', 'expires' => '2028-01-31']),
]));
