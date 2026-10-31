<?php

// The suite shortens waits that really sleep (phpunit.xml, TestCase::shortenWaits()). Production keeps its values, and a
// retry keeps its number of attempts: only the delay between them is gone.

use App\Modules\PaymentsNepal\Support\ProviderHttp;
use App\Modules\Platform\Support\MailServerSettings;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;

test('the sign-in timebox is off in the tests and 200 ms when AUTH_TIMEBOX_DURATION is unset', function () {
    $saved = [$_ENV['AUTH_TIMEBOX_DURATION'] ?? null, $_SERVER['AUTH_TIMEBOX_DURATION'] ?? null, getenv('AUTH_TIMEBOX_DURATION')];
    unset($_ENV['AUTH_TIMEBOX_DURATION'], $_SERVER['AUTH_TIMEBOX_DURATION']);
    putenv('AUTH_TIMEBOX_DURATION');

    try {
        $production = (require config_path('auth.php'))['timebox_duration'];
    } finally {
        [$env, $server, $put] = $saved;
        if ($env !== null) {
            $_ENV['AUTH_TIMEBOX_DURATION'] = $env;
        }
        if ($server !== null) {
            $_SERVER['AUTH_TIMEBOX_DURATION'] = $server;
        }
        if ($put !== false) {
            putenv("AUTH_TIMEBOX_DURATION={$put}");
        }
    }

    expect(config('auth.timebox_duration'))->toBe(0)
        ->and(Env::get('AUTH_TIMEBOX_DURATION'))->toBe('0')
        ->and($production)->toBe(200000);
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

    $response = app(ProviderHttp::class)->lookup()->get('https://lookup.example.test/status');

    expect($response->ok())->toBeTrue()->and(microtime(true) - $started)->toBeLessThan(1.0);
    Http::assertSentCount(3);
});

test('the mail test send waits briefly in the tests and keeps its production timeouts otherwise', function () {
    expect([MailServerSettings::testTimeout(), MailServerSettings::testDeadline()])->toBe([2, 4])
        ->and([MailServerSettings::TEST_TIMEOUT, MailServerSettings::TEST_DEADLINE])->toBe([10, 20]);

    config(['platform.mail_test' => null]);

    expect([MailServerSettings::testTimeout(), MailServerSettings::testDeadline()])->toBe([10, 20]);
});
