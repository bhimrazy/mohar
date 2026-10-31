<?php

// The suite shortens waits that really sleep (TestCase::shortenWaits()). Production keeps its values, and a
// retry keeps its number of attempts: only the delay between them is gone.

use App\Data\Billing\GatewayConfig;
use App\Modules\PaymentsNepal\Support\ProviderHttp;
use App\Modules\Platform\Support\MailServerSettings;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Http;

test('the SessionGuard timebox is off in the tests only: production config does not set it, so it stays 200 ms', function () {
    $guard = app('auth')->guard('web');

    expect(config('auth.timebox_duration'))->toBe(0)
        ->and((fn () => $this->timeboxDuration)->call($guard))->toBe(0)
        ->and((fn () => $this->timeboxDuration)->call(app(StatefulGuard::class)))->toBe(0)
        ->and(require config_path('auth.php'))->not->toHaveKey('timebox_duration');
});

test('lookup retries keep their number of attempts with no delay between them', function (string $module, string $file) {
    $production = (require base_path($file))['http']['lookup_retry_delays_ms'];

    expect($production)->not->toBe([])
        ->and(config("{$module}.http.lookup_retry_delays_ms"))->toBe(array_fill(0, count($production), 0));
})->with([
    ['payments_nepal', 'app/Modules/PaymentsNepal/Config/payments_nepal.php'],
    ['payments_cybersource', 'app/Modules/PaymentsCybersource/Config/payments_cybersource.php'],
    ['payments_stripe', 'app/Modules/PaymentsStripe/Config/payments_stripe.php'],
    ['payments_razorpay', 'app/Modules/PaymentsRazorpay/Config/payments_razorpay.php'],
]);

test('a background lookup still retries a 5xx as often as in production', function () {
    Http::fake(['https://lookup.example.test/*' => Http::sequence()->push('', 503)->push('', 503)->push('{"ok":true}', 200)]);
    $started = microtime(true);

    $response = app(ProviderHttp::class)->lookup(new GatewayConfig('khalti', 'Khalti'))->get('https://lookup.example.test/status');

    expect($response->ok())->toBeTrue()->and(microtime(true) - $started)->toBeLessThan(1.0);
    Http::assertSentCount(3);
});

test('the mail test send waits briefly in the tests and keeps its production timeouts otherwise', function () {
    expect([MailServerSettings::testTimeout(), MailServerSettings::testDeadline()])->toBe([2, 4])
        ->and([MailServerSettings::TEST_TIMEOUT, MailServerSettings::TEST_DEADLINE])->toBe([10, 20])
        ->and((require base_path('app/Modules/Platform/Config/platform.php'))['mail_test'])->toBe(['timeout_seconds' => 10, 'deadline_seconds' => 20]);

    config(['platform.mail_test' => null]);

    expect([MailServerSettings::testTimeout(), MailServerSettings::testDeadline()])->toBe([10, 20]);
});
