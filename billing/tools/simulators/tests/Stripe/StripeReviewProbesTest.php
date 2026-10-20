<?php

/*
 * Probes from the PR #113 review (ADR 0016 S3). Fidelity probes that fail at 8aa7fa2 pin the fixes; the rest pin
 * protections that hold today.
 */

use App\Data\Integrations\Endpoint;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Modules\PaymentsStripe\Support\WebhookSignature;
use App\Support\Integrations\Endpoints;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\RecordingScanner;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Providers\Stripe\StripeSimulator;
use Mohar\Simulators\Providers\Whm\LiveRecording;
use Tests\TestCase;

uses(TestCase::class);

function probeOverride(array $overrides): void
{
    config(['integrations.endpoints.overrides' => json_encode($overrides), 'integrations.outbound.allow_loopback' => true]);
    app()->forgetInstance(Endpoints::class);
}

function probeIsCheckoutUrl(string $url): bool
{
    return (fn (string $url): bool => $this->isCheckoutUrl($url))->call(app(StripeDriver::class), $url);
}

test('outside development an override never changes the Stripe base URL, its options or the checkout host rule', function () {
    probeOverride(['stripe.api' => 'https://127.0.0.1:9443/v1', 'stripe.checkout' => 'https://127.0.0.1:9443']);
    app()->detectEnvironment(fn () => 'production');

    expect(Endpoints::for('stripe.api', 'https://api.stripe.com/v1/'))->toEqual(new Endpoint('https://api.stripe.com/v1/'))
        ->and(Endpoints::for('stripe.api', 'https://api.stripe.com/v1/')->url('checkout/sessions'))->toBe('https://api.stripe.com/v1/checkout/sessions')
        ->and(probeIsCheckoutUrl('https://checkout.stripe.com/c/pay/cs_test_a#fid'))->toBeTrue()
        ->and(probeIsCheckoutUrl('https://127.0.0.1:9443/c/pay/cs_test_a'))->toBeFalse()
        ->and(probeIsCheckoutUrl('https://checkout.stripe.com:443/c/pay/cs_test_a'))->toBeFalse()
        ->and(probeIsCheckoutUrl('https://checkout.stripe.com.evil.test/c/pay/cs_test_a'))->toBeFalse()
        ->and(probeIsCheckoutUrl('https://user@checkout.stripe.com/c/pay/cs_test_a'))->toBeFalse()
        ->and(probeIsCheckoutUrl('http://checkout.stripe.com/c/pay/cs_test_a'))->toBeFalse();
});

test('in development the checkout rule follows the override exactly: host and port, nothing else', function () {
    probeOverride(['stripe.checkout' => 'https://127.0.0.1:9443']);

    expect(probeIsCheckoutUrl('https://127.0.0.1:9443/c/pay/cs_test_a'))->toBeTrue()
        ->and(probeIsCheckoutUrl('https://127.0.0.1:9444/c/pay/cs_test_a'))->toBeFalse()
        ->and(probeIsCheckoutUrl('https://checkout.stripe.com/c/pay/cs_test_a'))->toBeFalse();
});

test('a webhook the simulator signs verifies with the app\'s own verifier; the wrong secret and a stale one do not', function () {
    $scenario = Scenario::create(StripeSimulator::class);
    $simulator = new StripeSimulator;
    $body = '{"id":"evt_1","object":"event"}';

    try {
        expect(WebhookSignature::verify($simulator->sign($scenario, $body, time())['Stripe-Signature'], $body, StripeSimulator::WEBHOOK_SECRET, 300)[0])->toBe(WebhookSignature::VALID)
            ->and(WebhookSignature::verify($simulator->sign($scenario, $body, time(), wrongSecret: true)['Stripe-Signature'], $body, StripeSimulator::WEBHOOK_SECRET, 300)[0])->toBe(WebhookSignature::INVALID)
            ->and(WebhookSignature::verify($simulator->sign($scenario, $body, time() - 3600)['Stripe-Signature'], $body, StripeSimulator::WEBHOOK_SECRET, 300)[0])->toBe(WebhookSignature::STALE)
            ->and(WebhookSignature::verify($simulator->sign($scenario, $body, time())['Stripe-Signature'], $body.' ', StripeSimulator::WEBHOOK_SECRET, 300)[0])->toBe(WebhookSignature::INVALID);
    } finally {
        Processes::stop($scenario);
    }
});

test('the request log masks the secret key, client secrets and card numbers in a Stripe call', function () {
    $scenario = Scenario::create(StripeSimulator::class);

    try {
        $scenario->log()->record('create_checkout_session', new Request('POST', '/v1/checkout/sessions', [], ['authorization' => 'Bearer '.StripeSimulator::SECRET_KEY], 'metadata[note]=card+4242+4242+4242+4242&payment_method_data[card][number]=4000056655665556'), Mohar\Simulators\Http\Response::json(['client_secret' => 'pi_1_secret_abc123', 'webhook' => ['secret' => StripeSimulator::WEBHOOK_SECRET]]));
        $log = (string) json_encode($scenario->log()->entries());

        expect($log)->not->toContain(StripeSimulator::SECRET_KEY)
            ->and($log)->not->toContain('4242 4242 4242 4242')
            ->and($log)->not->toContain('4000056655665556')
            ->and($log)->not->toContain('pi_1_secret_abc123')
            ->and($log)->not->toContain(StripeSimulator::WEBHOOK_SECRET);
    } finally {
        Processes::stop($scenario);
    }
});

test('data used as a key is renamed or flagged: emails, addresses, domains, spaces, nesting', function (array $answer, string $needle) {
    $anonymised = LiveRecording::anonymise($answer);
    $file = sys_get_temp_dir().'/probe-key-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($file, json_encode(['source' => 'live', 'response' => $answer]));

    try {
        expect((string) json_encode($anonymised))->not->toContain($needle)
            ->and((new RecordingScanner)->scanFile($file))->not->toBe([]);
    } finally {
        unlink($file);
    }
})->with([
    'an email as a key' => [['data' => ['anna@alpha-books.com' => 1]], 'anna@alpha-books.com'],
    'a domain as a nested key' => [['data' => ['acct' => [['domains' => ['shop.alpha-books.com' => ['x' => 1]]]]]], 'alpha-books'],
    'an IPv6 address as a key' => [['data' => ['2001:4860::8888' => 'up']], '2001:4860'],
    'an IPv4 address as a key' => [['data' => ['8.8.8.8' => 'up']], '8.8.8.8'],
]);

describe('against a running stripe scenario', function () {
    beforeEach(function () {
        $this->scenario = Processes::start(StripeSimulator::class);
        $this->call = fn (string $path, array $params, ?string $key = null): Response => Http::withOptions(['verify' => $this->scenario->ca()->bundlePath(), 'allow_redirects' => false])
            ->withToken(StripeSimulator::SECRET_KEY)->withHeaders(array_filter(['Idempotency-Key' => $key]))->asForm()->post($this->scenario->apiUrl().$path, $params);
        $this->session = fn (int $amount = 2_500): array => ['mode' => 'payment', 'success_url' => 'https://shop.example.test/ok', 'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => $amount, 'product_data' => ['name' => 'Invoice 1']]]]];
        $this->paid = function (): string {
            $id = ($this->call)('/v1/checkout/sessions', ($this->session)())->json('id');
            Http::withOptions(['verify' => $this->scenario->ca()->bundlePath(), 'allow_redirects' => false])->post($this->scenario->apiUrl()."/c/pay/{$id}/pay");

            return (string) $this->scenario->state()->get('sessions', $id)['payment_intent'];
        };
    });

    afterEach(fn () => Processes::stop($this->scenario));

    test('a request that fails validation saves no idempotent result, so the corrected retry with the same key runs', function () {
        expect(($this->call)('/v1/checkout/sessions', ($this->session)(10), 'retry-key')->json('error.code'))->toBe('amount_too_small');

        $retry = ($this->call)('/v1/checkout/sessions', ($this->session)(2_500), 'retry-key');

        expect($retry->status())->toBe(200)
            ->and($retry->json('id'))->toStartWith('cs_test_');
    });

    test('a disputed charge cannot be refunded', function () {
        $intent = ($this->paid)();
        $this->scenario->simulator()->actions()['dispute'](['payment_intent' => $intent], $this->scenario);

        $refund = ($this->call)('/v1/refunds', ['payment_intent' => $intent], 'refund-disputed');

        expect($refund->status())->toBe(400)
            ->and($refund->json('error.code'))->toBe('charge_disputed');
    });

    test('a double click on Pay charges once', function () {
        $id = ($this->call)('/v1/checkout/sessions', ($this->session)())->json('id');
        $url = $this->scenario->apiUrl()."/c/pay/{$id}/pay";
        Http::pool(fn (Pool $pool) => array_map(fn () => $pool->withOptions(['verify' => $this->scenario->ca()->bundlePath(), 'allow_redirects' => false])->post($url), range(1, 6)));

        expect($this->scenario->state()->all('charges'))->toHaveCount(1)
            ->and(array_count_values(array_column($this->scenario->state()->all('events'), 'type'))['checkout.session.completed'] ?? 0)->toBe(1);
    });
});
