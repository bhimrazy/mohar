<?php

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Recorder;
use Mohar\Simulators\Providers\Stripe\StripeSimulator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The simulator over its TLS port against the driver's fixtures (tests/Fixtures/Stripe, written from the API reference
 * and trimmed to what the driver reads, so extra fields are fine): every fixture path with the same type, and the
 * request rules Stripe applies (key, version, idempotency).
 */
beforeEach(function () {
    $this->scenario = Processes::start(StripeSimulator::class);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

/**
 * @param  array<string, mixed>  $params
 * @param  array<string, string>  $headers
 */
function stripeCall(string $method, string $path, array $params = [], array $headers = []): Response
{
    $request = Http::withOptions(['verify' => test()->scenario->ca()->bundlePath(), 'allow_redirects' => false])
        ->withToken(StripeSimulator::SECRET_KEY)
        ->withHeaders(['Stripe-Version' => '2026-09-30.endive', ...$headers]);

    return $method === 'GET' ? $request->get(test()->scenario->apiUrl().$path, $params) : $request->asForm()->post(test()->scenario->apiUrl().$path, $params);
}

/**
 * Fixture paths missing from the answer, or with another type.
 *
 * @param  array<array-key, mixed>  $answer
 * @return list<string>
 */
function stripeShapeGaps(string $fixture, array $answer): array
{
    $expected = json_decode((string) file_get_contents(base_path("tests/Fixtures/Stripe/{$fixture}.json")), true);

    return array_values(array_filter(Recorder::differences($expected, $answer), fn (string $difference): bool => ! str_starts_with($difference, 'extra ')));
}

/**
 * @return array<string, mixed>
 */
function stripeSession(int $amount = 2_500): array
{
    return [
        'mode' => 'payment',
        'success_url' => 'https://shop.example.test/return?ref=abc-123',
        'cancel_url' => 'https://shop.example.test/return?ref=abc-123&cancelled=1',
        'client_reference_id' => 'abc-123',
        'customer_email' => 'client@example.test',
        'expires_at' => time() + 2_100,
        'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => $amount, 'product_data' => ['name' => 'Invoice 1001']]]],
        // Echoed back as sent; the driver's keys, as in the fixtures.
        'metadata' => $metadata = ['mohar_reference' => 'abc-123', 'mohar_invoice_id' => '1', 'mohar_amount' => (string) $amount, 'mohar_currency' => 'usd', 'mohar_flow' => 'checkout'],
        'payment_intent_data' => ['metadata' => $metadata],
    ];
}

test('a session, its payment, a refund and a dispute answer in the shapes of the fixtures', function () {
    $created = stripeCall('POST', '/v1/checkout/sessions', stripeSession(), ['Idempotency-Key' => 'k-1'])->json();
    expect(stripeShapeGaps('checkout_session_created', $created))->toBe([]);

    Http::withOptions(['verify' => $this->scenario->ca()->bundlePath(), 'allow_redirects' => false])->post($this->scenario->apiUrl()."/c/pay/{$created['id']}/pay");
    $paid = stripeCall('GET', "/v1/checkout/sessions/{$created['id']}", ['expand' => ['payment_intent.latest_charge.balance_transaction']])->json();
    $intent = stripeCall('GET', "/v1/payment_intents/{$paid['payment_intent']['id']}")->json();
    $refund = stripeCall('POST', '/v1/refunds', ['payment_intent' => $intent['id'], 'amount' => 1_000], ['Idempotency-Key' => 'k-2'])->json();
    $dispute = $this->scenario->simulator()->actions()['dispute'](['payment_intent' => $intent['id']], $this->scenario)['dispute'];
    $events = $this->scenario->state()->all('events');

    expect(stripeShapeGaps('checkout_session_paid', $paid))->toBe([])
        ->and($paid['payment_intent']['latest_charge']['balance_transaction']['fee'])->toBe(103)
        ->and(stripeShapeGaps('payment_intent_succeeded', $intent))->toBe([])
        ->and(stripeShapeGaps('refund', stripeCall('GET', "/v1/refunds/{$refund['id']}", ['expand' => ['payment_intent']])->json()))->toBe([])
        ->and(stripeShapeGaps('dispute', stripeCall('GET', "/v1/disputes/{$dispute}", ['expand' => ['payment_intent']])->json()))->toBe([])
        ->and(stripeShapeGaps('account', stripeCall('GET', '/v1/account')->json()))->toBe([])
        ->and(stripeShapeGaps('event', reset($events)))->toBe([])
        ->and(array_column($events, 'type'))->toEqualCanonicalizing(['checkout.session.completed', 'payment_intent.succeeded', 'refund.created', 'charge.refunded', 'charge.dispute.created', 'charge.dispute.funds_withdrawn'])
        // The request log keeps the secret key out (default rule) and card fields masked (payment rule).
        ->and((string) json_encode($this->scenario->log()->entries()))->not->toContain(StripeSimulator::SECRET_KEY);
});

test('errors use Stripe\'s envelope: a wrong key, a missing object, a refund past what is left, a bad amount', function () {
    $intent = (function (): string {
        $session = stripeCall('POST', '/v1/checkout/sessions', stripeSession())->json('id');
        Http::withOptions(['verify' => $this->scenario->ca()->bundlePath(), 'allow_redirects' => false])->post($this->scenario->apiUrl()."/c/pay/{$session}/pay");

        return (string) $this->scenario->state()->get('sessions', $session)['payment_intent'];
    })->call($this);

    $wrongKey = Http::withOptions(['verify' => $this->scenario->ca()->bundlePath()])->withToken('sk_test_wrong')->get($this->scenario->apiUrl().'/v1/account');
    $missing = stripeCall('GET', '/v1/checkout/sessions/cs_test_nothere0000');
    stripeCall('POST', '/v1/refunds', ['payment_intent' => $intent]);
    $again = stripeCall('POST', '/v1/refunds', ['payment_intent' => $intent]);
    $small = stripeCall('POST', '/v1/checkout/sessions', stripeSession(10));

    expect($wrongKey->status())->toBe(401)->and(stripeShapeGaps('error_invalid_key', $wrongKey->json()))->toBe([])
        ->and($missing->status())->toBe(404)->and(stripeShapeGaps('error_resource_missing', $missing->json()))->toBe([])
        ->and($again->status())->toBe(400)->and($again->json('error.code'))->toBe('charge_already_refunded')->and(stripeShapeGaps('error_already_refunded', $again->json()))->toBe([])
        ->and($small->status())->toBe(400)->and($small->json('error.code'))->toBe('amount_too_small');
});

test('an idempotency key replays its first answer, and refuses other parameters', function () {
    // One set of parameters for both calls: expires_at is built from the clock, and a replay must send the same body.
    $params = stripeSession();
    $first = stripeCall('POST', '/v1/checkout/sessions', $params, ['Idempotency-Key' => 'checkout-abc-123']);
    $replay = stripeCall('POST', '/v1/checkout/sessions', $params, ['Idempotency-Key' => 'checkout-abc-123']);
    $changed = stripeCall('POST', '/v1/checkout/sessions', stripeSession(3_000), ['Idempotency-Key' => 'checkout-abc-123']);

    expect($replay->json('id'))->toBe($first->json('id'))
        ->and($replay->header('Idempotent-Replayed'))->toBe('true')
        ->and($changed->status())->toBe(400)->and($changed->json('error.type'))->toBe('idempotency_error')
        ->and($this->scenario->state()->all('sessions'))->toHaveCount(1);
});

test('only a Stripe-Version the simulator knows is accepted', function () {
    expect(stripeCall('GET', '/v1/account', headers: ['Stripe-Version' => '2020-08-27'])->json('error.code'))->toBe('api_version_invalid')
        ->and(stripeCall('GET', '/v1/account')->successful())->toBeTrue();
});

test('a refusal after the request started is saved and replayed; a parameter error is not saved; expand needs an object', function () {
    $session = stripeCall('POST', '/v1/checkout/sessions', stripeSession())->json('id');
    Http::withOptions(['verify' => $this->scenario->ca()->bundlePath(), 'allow_redirects' => false])->post($this->scenario->apiUrl()."/c/pay/{$session}/pay");
    $intent = (string) $this->scenario->state()->get('sessions', $session)['payment_intent'];
    stripeCall('POST', '/v1/refunds', ['payment_intent' => $intent]);

    $refused = stripeCall('POST', '/v1/refunds', ['payment_intent' => $intent], ['Idempotency-Key' => 'refund-twice']);
    $replayed = stripeCall('POST', '/v1/refunds', ['payment_intent' => $intent], ['Idempotency-Key' => 'refund-twice']);
    $currency = stripeSession();
    $currency['line_items'][0]['price_data']['currency'] = 'dollars';
    $metadata = [...stripeSession(), 'metadata' => [str_repeat('k', 41) => 'v']];

    expect($refused->json('error.code'))->toBe('charge_already_refunded')
        ->and($replayed->header('Idempotent-Replayed'))->toBe('true')
        ->and(stripeCall('POST', '/v1/checkout/sessions', $currency, ['Idempotency-Key' => 'bad-currency'])->json('error.param'))->toBe('line_items[0][price_data][currency]')
        ->and(stripeCall('POST', '/v1/checkout/sessions', $metadata, ['Idempotency-Key' => 'bad-metadata'])->json('error.param'))->toBe('metadata')
        ->and($this->scenario->state()->get('idempotency', 'bad-currency'))->toBeNull()
        ->and(stripeCall('GET', "/v1/payment_intents/{$intent}", ['expand' => ['currency']])->json('error.message'))->toContain('cannot be expanded')
        ->and(stripeCall('GET', "/v1/checkout/sessions/{$session}", ['expand' => ['payment_intent.latest_charge']])->json('payment_intent.latest_charge.object'))->toBe('charge');
});
