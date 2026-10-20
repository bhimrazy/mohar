<?php

/*
 * PR #113 final re-check probes (head ad151e9c): `simulate:record stripe` with a fake key against faked test-mode
 * answers that carry values the app never sent: extra metadata keys (another integration on the account, or a
 * dashboard edit), including one named `object`, and extra children on expanded objects. None of them may reach a
 * saved recording. Fake values only; no network.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Providers\Stripe\StripeRecording;
use Tests\TestCase;

uses(TestCase::class);

const RR113F_KEY = 'sk_test_FakeKeyForTheFinalRecheck000000000';

/** Values the app never sent; none may be saved. */
const RR113F_INJECTED = ['Anna Smith', '+15555550123', 'anna.smith@annahosting.co.uk', '42 Elm Street', 'Abc123Def456', 'FakeExpandedSecret'];

/**
 * @return array<array-key, mixed>
 */
function rr113fAnswer(Request $request): array
{
    $path = (string) parse_url($request->url(), PHP_URL_PATH);
    // The app's own keys, plus keys the app never sent.
    $metadata = ['mohar_reference' => 'drift-1', 'mohar_flow' => 'checkout', 'billing_name' => 'Anna Smith', 'support_line' => '+15555550123', 'object' => 'anna.smith@annahosting.co.uk'];
    $error = fn (string $code): array => ['error' => ['code' => $code, 'message' => 'Contact anna.smith@annahosting.co.uk', 'type' => 'invalid_request_error']];
    $intent = ['id' => 'pi_3Fake', 'object' => 'payment_intent', 'amount' => 2500, 'amount_received' => 2500, 'currency' => 'usd', 'last_payment_error' => null, 'latest_charge' => 'ch_3Fake', 'livemode' => false, 'metadata' => $metadata, 'status' => 'succeeded',
        'shipping' => ['name' => 'Anna Smith', 'address' => ['line1' => '42 Elm Street']]];
    $expandedIntent = [...$intent, 'latest_charge' => ['id' => 'ch_3Fake', 'object' => 'charge', 'amount_refunded' => 0, 'refunded' => false, 'billing_details' => ['name' => 'Anna Smith', 'phone' => '+15555550123'],
        'balance_transaction' => ['id' => 'txn_3Fake', 'object' => 'balance_transaction', 'currency' => 'usd', 'fee' => 103, 'source' => ['fingerprint' => 'Abc123Def456']]]];
    $session = fn (string $status): array => ['id' => 'cs_test_a1B2', 'object' => 'checkout.session', 'amount_total' => 2500, 'client_reference_id' => 'drift-1', 'currency' => 'usd', 'expires_at' => 1792302100, 'livemode' => false, 'metadata' => $metadata, 'mode' => 'payment',
        'payment_intent' => [...$intent, 'client_secret' => 'pi_3Fake_secret_FakeExpandedSecret'], 'payment_status' => 'unpaid', 'status' => $status, 'url' => null, 'customer_details' => ['name' => 'Anna Smith']];
    $refund = fn (int $amount): array => ['id' => 're_3Fake', 'object' => 'refund', 'amount' => $amount, 'currency' => 'usd', 'metadata' => $metadata, 'payment_intent' => $intent, 'reason' => null, 'status' => 'succeeded'];

    return match (true) {
        $path === '/v1/account' => ['id' => 'acct_1Fake', 'object' => 'account', 'business_profile' => ['name' => 'Anna Smith', 'support_phone' => '+15555550123', 'support_address' => ['line1' => '42 Elm Street']]],
        $path === '/v1/checkout/sessions/cs_test_driftmissing000000' => $error('resource_missing'),
        $path === '/v1/checkout/sessions' && (int) ($request->data()['line_items'][0]['price_data']['unit_amount'] ?? 0) < 50 => $error('amount_too_small'),
        str_ends_with($path, '/expire') => $session('expired'),
        str_starts_with($path, '/v1/checkout/sessions') => $session('open'),
        $path === '/v1/payment_intents' => $intent,
        str_starts_with($path, '/v1/payment_intents/') => $expandedIntent,
        $path === '/v1/refunds' && isset($request->data()['amount']) => $refund(1000),
        str_starts_with($path, '/v1/refunds/') => $refund(1000),
        $path === '/v1/refunds' && ! test()->refunded => (function () use ($refund): array {
            test()->refunded = true;

            return $refund(1500);
        })(),
        default => $error('charge_already_refunded'),
    };
}

beforeEach(function () {
    $this->directory = dirname(__DIR__, 2).'/stripe/recordings';
    $this->existed = is_dir($this->directory);
    $this->refunded = false;
    $_SERVER['STRIPE_RECORD_KEY'] = RR113F_KEY;
    Http::preventStrayRequests();
    Http::fake(['api.stripe.com/*' => function (Request $request) {
        $answer = rr113fAnswer($request);

        return Http::response($answer, isset($answer['error']) ? 400 : 200);
    }]);
});

afterEach(function () {
    unset($_SERVER['STRIPE_RECORD_KEY']);

    if (! $this->existed && is_dir($this->directory)) {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
        @rmdir(dirname($this->directory));
    }
});

test('values the app never sent, in metadata or expanded objects, are not saved', function () {
    $this->artisan('simulate:record', ['provider' => 'stripe'])->run();
    $written = implode("\n", array_map('file_get_contents', glob($this->directory.'/*.json') ?: []));

    expect(array_values(array_filter([...RR113F_INJECTED, RR113F_KEY], fn (string $value): bool => str_contains($written, $value))))->toBe([]);
});

test('the run is still saved, so the check above is not vacuous', function () {
    $this->artisan('simulate:record', ['provider' => 'stripe'])->run();

    expect(glob($this->directory.'/*.json') ?: [])->toHaveCount(count(StripeRecording::steps()));
});
