<?php

use App\Data\Billing\GatewayConfig;
use App\Modules\Billing\Services\GatewayManager;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Support\Billing\GatewayHttp;
use App\Support\MinorUnits;

test('a single-shot request takes the staff timeout from GatewayConfig unless the extension sets its own', function () {
    config(['acme_pay.http' => ['connect_timeout_seconds' => 4]]);
    $gateway = new GatewayConfig('acme_pay', 'Acme', httpTimeoutSeconds: 20);

    expect(GatewayHttp::for('acme_pay.http')->request($gateway)->getOptions())->toMatchArray(['connect_timeout' => 4, 'timeout' => 20]);

    config(['acme_pay.http.timeout_seconds' => 9]);
    expect(GatewayHttp::for('acme_pay.http')->request($gateway)->getOptions())->toMatchArray(['timeout' => 9])
        ->and(GatewayHttp::for('acme_pay.http')->interactive()->getOptions())->toMatchArray(['connect_timeout' => 3, 'timeout' => 6]);
});

test('the staff timeout reaches drivers through GatewayConfig, at least 3 seconds', function () {
    app(Settings::class)->set('billing.gateways.http_timeout_seconds', 25);
    expect(app(GatewayManager::class)->config('stripe')->httpTimeoutSeconds)->toBe(25);

    app(Settings::class)->set('billing.gateways.http_timeout_seconds', 1);
    expect(app(GatewayManager::class)->config('stripe')->httpTimeoutSeconds)->toBe(3);
});

test('an endpoint must be an https URL to be pinned', function () {
    expect(GatewayHttp::endpoint('acme.api', 'https://api.acme.test/v1')?->url('ping'))->toBe('https://api.acme.test/v1/ping')
        ->and(GatewayHttp::endpoint('acme.api', 'http://api.acme.test'))->toBeNull()
        ->and(GatewayHttp::endpoint('acme.api', null))->toBeNull();
});

test('minor-unit decimals come from the configured currencies, 2 when unknown', function () {
    Currency::factory()->create(['code' => 'JPY', 'name' => 'Japanese yen', 'decimals' => 0, 'is_default' => false]);

    expect(MinorUnits::decimals('jpy'))->toBe(0)
        ->and(MinorUnits::decimals('XTS'))->toBe(2);
});
