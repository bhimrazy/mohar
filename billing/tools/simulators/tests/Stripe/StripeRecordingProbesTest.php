<?php

/*
 * PR #113 re-check probes (head 4a677e8b): `simulate:record stripe` with a fake key against faked, realistic
 * test-mode answers (no network, no real key). The recorder must never write the key or account and customer data
 * into the committed recordings directory, and a normal run must be savable. The drift check must also see a changed
 * error code, which the driver branches on.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Recorder;
use Mohar\Simulators\Providers\Stripe\StripeRecording;
use Tests\TestCase;

uses(TestCase::class);

const RR113_FAKE_KEY = 'sk_test_FakeKeyForTheRecheckProbe0000000000';

/** Account and customer values a live answer carries; none may reach a recording file. */
const RR113_SENSITIVE = [
    RR113_FAKE_KEY, 'acct_1FakeAcctRecheck', 'anna.smith@annahosting.example', 'Anna Smith', '+15555550123', '42 Elm Street',
    '110000000', 'req_AbCdEf1234567', 'CAcaFwoVYWNjdF8xRmFrZQ', 'Abc123Def456', 'fidkdWxOYHwnPyd1blpxYHZxWjA0',
];

/**
 * Answers shaped as Stripe's test mode gives them, for each scripted step.
 *
 * @return array<array-key, mixed>
 */
function rr113LiveAnswer(Request $request): array
{
    $path = (string) parse_url($request->url(), PHP_URL_PATH);
    $error = fn (string $code, string $message): array => ['error' => ['code' => $code, 'doc_url' => "https://stripe.com/docs/error-codes/{$code}", 'message' => $message, 'request_log_url' => 'https://dashboard.stripe.com/acct_1FakeAcctRecheck/test/logs/req_AbCdEf1234567?t=1792300000', 'type' => 'invalid_request_error']];
    $session = fn (string $status): array => ['id' => 'cs_test_a1B2c3D4e5F6', 'object' => 'checkout.session', 'amount_total' => 2500, 'client_reference_id' => 'drift-1', 'currency' => 'usd', 'created' => 1792300000, 'expires_at' => 1792302100, 'livemode' => false, 'metadata' => ['mohar_reference' => 'drift-1', 'mohar_flow' => 'checkout'], 'mode' => 'payment', 'payment_intent' => null, 'payment_status' => 'unpaid', 'status' => $status, 'url' => $status === 'open' ? 'https://checkout.stripe.com/c/pay/cs_test_a1B2c3D4e5F6#fidkdWxOYHwnPyd1blpxYHZxWjA0' : null, 'customer_details' => null];
    $intent = ['id' => 'pi_3FakeIntent', 'object' => 'payment_intent', 'amount' => 2500, 'amount_received' => 2500, 'client_secret' => 'pi_3FakeIntent_secret_AbCdEf', 'currency' => 'usd', 'last_payment_error' => null, 'latest_charge' => 'ch_3FakeCharge', 'livemode' => false, 'metadata' => ['mohar_reference' => 'drift-1', 'mohar_flow' => 'checkout'], 'status' => 'succeeded'];
    $charge = ['id' => 'ch_3FakeCharge', 'object' => 'charge', 'amount' => 2500, 'balance_transaction' => ['id' => 'txn_3Fake', 'object' => 'balance_transaction', 'fee' => 103, 'net' => 2397], 'billing_details' => ['email' => 'anna.smith@annahosting.example', 'name' => 'Anna Smith', 'phone' => '+15555550123', 'address' => ['line1' => '42 Elm Street', 'city' => 'Springfield', 'postal_code' => '94107', 'country' => 'US']], 'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242', 'fingerprint' => 'Abc123Def456', 'exp_month' => 12, 'exp_year' => 2034]], 'receipt_url' => 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xRmFrZQ'];
    $refund = fn (int $amount): array => ['id' => 're_3FakeRefund', 'object' => 'refund', 'amount' => $amount, 'currency' => 'usd', 'metadata' => [], 'payment_intent' => 'pi_3FakeIntent', 'reason' => null, 'status' => 'succeeded'];

    return match (true) {
        $path === '/v1/account' => ['id' => 'acct_1FakeAcctRecheck', 'object' => 'account', 'email' => 'anna.smith@annahosting.example', 'business_profile' => ['name' => 'Anna Smith Hosting', 'support_email' => 'anna.smith@annahosting.example', 'support_phone' => '+15555550123', 'support_address' => ['line1' => '42 Elm Street', 'city' => 'Springfield', 'postal_code' => '94107', 'country' => 'US']], 'external_accounts' => ['object' => 'list', 'data' => [['id' => 'ba_1Fake', 'object' => 'bank_account', 'account_holder_name' => 'Anna Smith', 'last4' => '6789', 'routing_number' => '110000000']]]],
        $path === '/v1/checkout/sessions/cs_test_driftmissing000000' => $error('resource_missing', "No such checkout.session: 'cs_test_driftmissing000000'"),
        $path === '/v1/checkout/sessions' && (int) ($request->data()['line_items'][0]['price_data']['unit_amount'] ?? 0) < 50 => $error('amount_too_small', 'Amount must be at least $0.50 usd'),
        $path === '/v1/checkout/sessions', str_ends_with($path, '/cs_test_a1B2c3D4e5F6') => $session('open'),
        str_ends_with($path, '/expire') => $session('expired'),
        $path === '/v1/payment_intents' => $intent,
        str_starts_with($path, '/v1/payment_intents/') => [...$intent, 'latest_charge' => $charge],
        $path === '/v1/refunds' && ($request->data()['amount'] ?? null) !== null => $refund(1000),
        str_starts_with($path, '/v1/refunds/') => [...$refund(1000), 'payment_intent' => $intent],
        $path === '/v1/refunds' && ! test()->refundedOnce => (function () use ($refund): array {
            test()->refundedOnce = true;

            return $refund(1500);
        })(),
        default => $error('charge_already_refunded', 'Charge ch_3FakeCharge has already been refunded.'),
    };
}

beforeEach(function () {
    $this->directory = dirname(__DIR__, 2).'/stripe/recordings';
    $this->existed = is_dir($this->directory);
    $this->refundedOnce = false;
    Http::preventStrayRequests();
    Http::fake(['api.stripe.com/*' => fn (Request $request) => Http::response(rr113LiveAnswer($request), isset(rr113LiveAnswer($request)['error']) ? 400 : 200)]);
});

afterEach(function () {
    unset($_SERVER['STRIPE_RECORD_KEY'], $_ENV['STRIPE_RECORD_KEY']);
    putenv('STRIPE_RECORD_KEY');

    if (! $this->existed && is_dir($this->directory)) {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
        @rmdir(dirname($this->directory));
    }
});

test('a live key is refused before any call is made', function () {
    $_SERVER['STRIPE_RECORD_KEY'] = 'sk_live_FakeLiveKeyNeverReal000000000';

    expect($this->artisan('simulate:record', ['provider' => 'stripe'])->run())->toBe(1);
    Http::assertNothingSent();
});

test('whatever the outcome, no key, account or customer value reaches the committed recordings', function () {
    $_SERVER['STRIPE_RECORD_KEY'] = RR113_FAKE_KEY;
    $this->artisan('simulate:record', ['provider' => 'stripe'])->run();

    $written = implode("\n", array_map('file_get_contents', glob($this->directory.'/*.json') ?: []));

    foreach (RR113_SENSITIVE as $value) {
        expect($written)->not->toContain($value);
    }
});

test('a normal test-mode run is saved: Stripe\'s own doc and log URLs do not fail the secret scan', function () {
    $_SERVER['STRIPE_RECORD_KEY'] = RR113_FAKE_KEY;

    // At 4a677e8b every error answer keeps doc_url (stripe.com) and request_log_url (dashboard.stripe.com), which the
    // scan reports as real-looking host names, so no live run can ever be saved.
    $this->artisan('simulate:record', ['provider' => 'stripe'])->assertExitCode(0);

    expect(glob($this->directory.'/*.json') ?: [])->toHaveCount(count(StripeRecording::steps()));
});

test('a recording keeps no account or customer value, even where the secret scan cannot see it', function () {
    $_SERVER['STRIPE_RECORD_KEY'] = RR113_FAKE_KEY;
    $staging = sys_get_temp_dir().'/rr113-staging-'.bin2hex(random_bytes(4));

    try {
        // What simulate:record writes before its scan. At 4a677e8b the scan refuses it only for Stripe's host names;
        // a street address, a holder name, a phone, a card fingerprint and a receipt URL are not things it looks for.
        (new StripeRecording)->record(new Recorder($staging, payments: true));
        $written = implode("\n", array_map('file_get_contents', glob($staging.'/*.json') ?: []));

        expect(array_values(array_filter(RR113_SENSITIVE, fn (string $value): bool => str_contains($written, $value))))->toBe([]);
    } finally {
        array_map('unlink', glob($staging.'/*') ?: []);
        @rmdir($staging);
    }
});

test('a different error code on a refusal the driver branches on is drift', function () {
    $recording = ['source' => 'live', 'context' => ['step' => 'refund_again', 'kind' => 'error'], 'shape' => ['error' => 'object', 'error.code' => 'string', 'error.message' => 'string', 'error.type' => 'string'], 'response' => ['error' => ['code' => 'charge_already_refunded', 'message' => 'x', 'type' => 'invalid_request_error']]];

    expect(StripeRecording::drift($recording, ['error' => ['code' => 'amount_too_large', 'message' => 'x', 'type' => 'invalid_request_error']]))->not->toBe([]);
});
