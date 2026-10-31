<?php

use App\Data\Billing\GatewayConfig;
use App\Modules\Billing\Services\GatewayManager;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Support\Billing\GatewayHttp;
use App\Support\MinorUnits;
use Illuminate\Support\Facades\Http;

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

test('only lookups are retried: a single-shot request is sent once whatever the answer', function () {
    config(['acme_pay.http' => ['lookup_retry_delays_ms' => [0, 0]]]);
    $gateway = new GatewayConfig('acme_pay', 'Acme');
    Http::fake(['acme.test/*' => Http::sequence()->push('', 503)->push('', 503)->push('{}', 200)]);

    $charge = GatewayHttp::for('acme_pay.http')->request($gateway)->post('https://acme.test/charge');

    expect($charge->status())->toBe(503);
    Http::assertSentCount(1);
});

test('a lookup is retried on 5xx and connection errors, never on 4xx', function () {
    config(['acme_pay.http' => ['lookup_retry_delays_ms' => [0, 0]]]);
    $gateway = new GatewayConfig('acme_pay', 'Acme');

    Http::fake(['acme.test/*' => Http::sequence()->push('', 503)->push('', 502)->push('{"ok":true}', 200)]);
    expect(GatewayHttp::for('acme_pay.http')->lookup($gateway)->get('https://acme.test/status')->status())->toBe(200);
    Http::assertSentCount(3);
});

test('a lookup answered 4xx is returned at once', function () {
    config(['acme_pay.http' => ['lookup_retry_delays_ms' => [0, 0]]]);
    Http::fake(['acme.test/*' => Http::sequence()->push('{"status":"Expired"}', 400)->push('{}', 200)]);

    expect(GatewayHttp::for('acme_pay.http')->lookup(new GatewayConfig('acme_pay', 'Acme'))->get('https://acme.test/status')->status())->toBe(400);
    Http::assertSentCount(1);
});

test('the bundled gateways keep their own HTTP timeouts: Cybersource 15 s, the others the staff timeout', function () {
    $gateway = new GatewayConfig('x', 'X', httpTimeoutSeconds: 40);

    expect(GatewayHttp::for('payments_cybersource.http')->request($gateway)->getOptions())->toMatchArray(['timeout' => 15, 'connect_timeout' => 5]);

    foreach (['payments_stripe.http', 'payments_razorpay.http', 'payments_nepal.http'] as $config) {
        expect(GatewayHttp::for($config)->request($gateway)->getOptions())->toMatchArray(['timeout' => 40, 'connect_timeout' => 5])
            ->and(GatewayHttp::for($config)->interactive()->getOptions())->toMatchArray(['timeout' => 6, 'connect_timeout' => 3]);
    }
});
