<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Http::preventStrayRequests());

test('the smoke command pays and refunds in test mode without printing the key', function () {
    $session = ['id' => STRIPE_SESSION, 'object' => 'checkout.session', 'mode' => 'payment', 'livemode' => false, 'payment_status' => 'unpaid', 'payment_intent' => null,
        'url' => 'https://checkout.stripe.com/c/pay/'.STRIPE_SESSION, 'expires_at' => now()->addMinutes(35)->getTimestamp()];
    Http::fake(function (Request $request) use ($session) {
        $reference = $request->method() === 'POST' && str_ends_with($request->url(), 'checkout/sessions') ? $request['client_reference_id'] : cache('smoke-ref');
        cache(['smoke-ref' => $reference]);
        $session = [...$session, 'client_reference_id' => $reference, 'metadata' => ['mohar_reference' => $reference]];

        return match (true) {
            $request->url() === STRIPE_API.'account' => Http::response(stripeFixture('account')),
            $request->url() === STRIPE_API.'checkout/sessions' => Http::response([...$session, 'status' => 'open']),
            str_ends_with($request->url(), '/expire') => Http::response([...$session, 'status' => 'expired']),
            str_starts_with($request->url(), STRIPE_API.'checkout/sessions/') => Http::response([...$session, 'status' => Http::recorded(fn (Request $sent) => str_ends_with($sent->url(), '/expire'))->isEmpty() ? 'open' : 'expired']),
            $request->url() === STRIPE_API.'payment_intents' => Http::response(stripeFixture('payment_intent_succeeded', ['amount' => 1_000, 'amount_received' => 1_000])),
            $request->url() === STRIPE_API.'refunds' => Http::response(stripeFixture('refund', ['amount' => $request['amount']])),
            str_starts_with($request->url(), STRIPE_API.'payment_intents/') => Http::response(stripeFixture('payment_intent_succeeded', ['latest_charge' => ['id' => 'ch_3MoharCharge0001', 'amount_refunded' => 1_000]])),
        };
    });

    $this->artisan('payments:stripe-smoke', ['--secret-key' => STRIPE_SECRET])
        ->expectsOutputToContain('Stripe smoke test passed')
        ->doesntExpectOutputToContain(STRIPE_SECRET)
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === STRIPE_API.'payment_intents' && $request['payment_method'] === 'pm_card_visa' && $request['amount'] === 1_000);
    expect(Http::recorded(fn (Request $request) => $request->url() === STRIPE_API.'refunds')->map(fn (array $pair) => $pair[0]['amount'])->values()->all())->toBe([500, 500]);
});

test('the smoke command refuses live keys and production', function () {
    $this->artisan('payments:stripe-smoke', ['--secret-key' => 'sk_live_51MoharFakeLiveKey000000000000'])
        ->expectsOutputToContain('Live keys are refused')->assertFailed();

    app()->detectEnvironment(fn () => 'production');
    $this->artisan('payments:stripe-smoke', ['--secret-key' => STRIPE_SECRET])
        ->expectsOutputToContain('not allowed in this environment')->assertFailed();

    Http::assertNothingSent();
});
