<?php

use App\Contracts\Services\ProvisioningDrivers;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Extensions\AcmePanel\AcmePanelServiceProvider;
use Tests\Support\Contracts\ProvisioningDriverContract;
use Tests\TestCase;

beforeAll(fn () => TestCase::$onlyModules = 'Services');
afterAll(fn () => TestCase::$onlyModules = null);

beforeEach(fn () => $this->app->register(AcmePanelServiceProvider::class));

test('with Services loaded, the package driver is listed for the product editor next to the built-in drivers', function () {
    $drivers = collect(app(ProvisioningDrivers::class)->all())->keyBy('key');

    expect($drivers->keys()->all())->toBe(['none', 'log', 'cpanel', 'acme_panel'])
        ->and(app()->providerIsLoaded('App\Modules\Catalog\CatalogServiceProvider'))->toBeFalse()
        ->and($drivers['acme_panel'])->toBe([
            'key' => 'acme_panel',
            'label' => 'Acme Panel',
            'fields' => [
                ['key' => 'plan', 'label' => 'Acme plan', 'type' => 'string', 'secret' => false, 'required' => true, 'help' => 'The plan name as it appears in Acme Panel.', 'options' => [], 'default' => null, 'options_source' => null],
                ['key' => 'reseller_key', 'label' => 'Reseller key', 'type' => 'secret', 'secret' => true, 'required' => false, 'help' => null, 'options' => [], 'default' => null, 'options_source' => null],
            ],
            'config_fields' => [
                ['key' => 'plan', 'label' => 'Acme plan', 'type' => 'text', 'required' => true, 'secret' => false],
                ['key' => 'reseller_key', 'label' => 'Reseller key', 'type' => 'password', 'required' => false, 'secret' => true],
            ],
        ]);
});

ProvisioningDriverContract::verify('acme_panel', arrange: fn () => Http::fake([
    'panel.contract.test:8443/api/accounts' => Http::response(['id' => 'acme-1', 'status' => 'created']),
    'panel.contract.test:8443/api/status' => Http::response(['version' => '3.2']),
    'panel.contract.test:8443/*' => Http::response(['ok' => true]),
]));
