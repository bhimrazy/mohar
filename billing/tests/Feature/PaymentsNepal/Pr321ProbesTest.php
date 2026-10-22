<?php

use App\Data\Billing\GatewayConfig;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use App\Support\Integrations\Endpoints;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

/**
 * Review probes for PR #321: the payment_url and Test connection checks with and without an endpoint override.
 */
function pr321Override(array $overrides, string $env = 'testing'): void
{
    config(['integrations.outbound.allow_loopback' => true, 'integrations.endpoints.overrides' => json_encode($overrides, JSON_UNESCAPED_SLASHES)]);
    app()->detectEnvironment(fn () => $env);
    app()->forgetInstance(Endpoints::class);
}

function pr321Initiate(string $paymentUrl): ?string
{
    [, $invoice] = nprInvoice();
    Http::fake(['https://dev.khalti.com/api/v2/epayment/initiate/' => Http::response(['pidx' => 'PIDX1', 'payment_url' => $paymentUrl])]);
    $location = test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->headers->get('Location');

    return PaymentAttempt::query()->sole()->status === AttemptStatus::Failed ? null : $location;
}

beforeEach(function () {
    Http::preventStrayRequests();
    nepalGateway(KhaltiDriver::KEY, ['sandbox' => '1', 'secret_key' => 'test_secret_key_probe']);
});

test('no override: hosts that are not khalti.com are refused, khalti.com itself is accepted', function (string $url, bool $accepted) {
    expect(pr321Initiate($url) !== null)->toBe($accepted);
})->with([
    'khalti.com' => ['https://khalti.com/?pidx=PIDX1', true],
    'khalti.com:443' => ['https://khalti.com:443/?pidx=PIDX1', false],
    'loopback' => ['https://127.0.0.1/?pidx=PIDX1', false],
    'loopback with port' => ['https://127.0.0.1:9443/?pidx=PIDX1', false],
    'no host' => ['https:?pidx=PIDX1', false],
    'empty host' => ['https:///?pidx=PIDX1', false],
]);

test('override active: only the exact khalti.pay host and port is accepted besides khalti.com', function (string $url, bool $accepted) {
    pr321Override(['khalti.pay' => 'https://127.0.0.1:9443']);
    expect(pr321Initiate($url) !== null)->toBe($accepted);
})->with([
    'exact' => ['https://127.0.0.1:9443/?pidx=PIDX1', true],
    'other port' => ['https://127.0.0.1:9444/?pidx=PIDX1', false],
    'no port' => ['https://127.0.0.1/?pidx=PIDX1', false],
    'http' => ['http://127.0.0.1:9443/?pidx=PIDX1', false],
    'userinfo' => ['https://evil@127.0.0.1:9443/?pidx=PIDX1', false],
    'other host' => ['https://evil.example:9443/?pidx=PIDX1', false],
]);

test('an override set in production is ignored by the payment_url check', function () {
    pr321Override(['khalti.pay' => 'https://127.0.0.1:9443'], 'production');
    $method = new ReflectionMethod(KhaltiDriver::class, 'isKhaltiUrl');
    expect($method->invoke(app(KhaltiDriver::class), 'https://127.0.0.1:9443/?pidx=PIDX1'))->toBeFalse()
        ->and($method->invoke(app(KhaltiDriver::class), 'https://pay.khalti.com/?pidx=PIDX1'))->toBeTrue();
});

test('eSewa Test connection: a redirect off eSewa is not accepted without an override', function (string $location, bool $ok) {
    Http::fake(['https://rc-epay.esewa.com.np/api/epay/main/v2/form' => Http::response('', 302, ['Location' => $location])]);
    $config = new GatewayConfig(EsewaDriver::KEY, 'eSewa', ['sandbox' => '1', 'product_code' => 'EPAYTEST', 'secret_key' => 'k'], sandbox: true);

    expect(app(EsewaDriver::class)->testConnection($config)->ok)->toBe($ok);
})->with([
    'esewa login' => ['https://rc-epay.esewa.com.np/epay?bookingId=a', true],
    'evil host' => ['https://evil.example/login', false],
    'relative' => ['/login', false],
    // Was accepted by the old substring check; the host must now be esewa.com.np or end in .esewa.com.np.
    'look-alike' => ['https://esewa.com.np.evil.example/login', false],
    'look-alike prefix' => ['https://evilesewa.com.np/login', false],
    'esewa.com.np itself, any case' => ['https://ESEWA.com.np/login', true],
]);

test('an override the outbound policy refuses never throws from the payment_url check: khalti.com still applies', function () {
    pr321Override(['khalti.pay' => 'https://127.0.0.1:9443']);
    config(['integrations.outbound.allow_loopback' => false]);
    $method = new ReflectionMethod(KhaltiDriver::class, 'isKhaltiUrl');

    expect($method->invoke(app(KhaltiDriver::class), 'https://127.0.0.1:9443/?pidx=PIDX1'))->toBeFalse()
        ->and($method->invoke(app(KhaltiDriver::class), 'https://pay.khalti.com/?pidx=PIDX1'))->toBeTrue();
});
