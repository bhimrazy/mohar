<?php

use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Actions\ConfigureGateway;
use App\Modules\Billing\Actions\StartHostedPayment;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\GatewayManager;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Modules\PaymentsStripe\Support\StripeAmount;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    stripeGateway();
});

function stripeReturn(PaymentAttempt $attempt, array $query = []): string
{
    return route('billing.gateways.return', [StripeDriver::KEY, 'ref' => $attempt->reference, ...$query]);
}

function fakeSessionRetrieve(array|int ...$responses): void
{
    $sequence = Http::sequence();

    foreach ($responses as $response) {
        is_int($response) ? $sequence->push(stripeFixture('error_resource_missing'), $response) : $sequence->push($response);
    }

    Http::fake([STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'*' => $sequence]);
}

test('starting a payment creates a Checkout Session with our reference, exact amount, pinned version and idempotency key', function () {
    [, $invoice, $attempt] = startStripe(2_500);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === STRIPE_API.'checkout/sessions'
        && $request->header('Authorization') === ['Bearer '.STRIPE_SECRET]
        && $request->header('Stripe-Version') === ['2026-09-30.endive']
        && $request->header('Idempotency-Key') === ['mohar-checkout-'.$attempt->reference]
        && $request['mode'] === 'payment'
        && $request['client_reference_id'] === $attempt->reference
        && $request['line_items'][0]['price_data'] === ['currency' => 'usd', 'unit_amount' => 2500, 'product_data' => ['name' => 'Invoice '.$invoice->displayNumber()]]
        && $request['line_items'][0]['quantity'] === 1
        && $request['success_url'] === route('billing.gateways.return', StripeDriver::KEY).'?ref='.$attempt->reference
        && $request['cancel_url'] === route('billing.gateways.return', [StripeDriver::KEY, 'ref' => $attempt->reference, 'cancelled' => 1])
        && $request['adaptive_pricing'] === ['enabled' => 'false']
        && $request['allowed_payment_method_types'] === ['card']
        && $request['metadata'] === ['mohar_reference' => $attempt->reference, 'mohar_invoice_id' => (string) $invoice->id, 'mohar_amount' => '2500', 'mohar_currency' => 'usd', 'mohar_flow' => 'checkout']
        && $request['payment_intent_data']['metadata'] === $request['metadata']);

    expect($attempt)->status->toBe(AttemptStatus::Pending)->provider_ref->toBe(STRIPE_SESSION)->amount->toBe(2_500)->currency->toBe('USD')
        ->and($attempt->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(34)->toBeLessThan(36);
});

test('a return pays nothing until the session retrieve confirms it, then the poller settles it with the fee', function () {
    Event::fake([InvoicePaid::class]);
    [, $invoice, $attempt] = startStripe();
    fakeSessionRetrieve(stripeCreatedSession($attempt->reference, $invoice->id), stripePaidSession($attempt));

    // The browser can't choose which session is looked up.
    $this->get(stripeReturn($attempt, ['session_id' => 'cs_test_attacker000000']))->assertRedirect(route('client.invoices.show', $invoice->id));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)->and($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_starts_with($request->url(), STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'?expand')
        && str_contains(urldecode($request->url()), 'expand[]=payment_intent.latest_charge.balance_transaction'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'attacker'));

    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->provider_txn_id->toBe(STRIPE_INTENT)
        ->and(Transaction::query()->where('gateway', StripeDriver::KEY)->sole())->gateway_txn_id->toBe(STRIPE_INTENT)->amount_in->toBe(2_500)->fee->toBe(103);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

test('a retrieve that disagrees with the attempt records nothing and goes to staff review', function (Closure $session, string $reason) {
    [, $invoice, $attempt] = startStripe();
    fakeSessionRetrieve($session($attempt));

    $this->get(stripeReturn($attempt));

    expect(Transaction::query()->where('gateway', StripeDriver::KEY)->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toContain($reason);
})->with([
    'amount' => [fn (PaymentAttempt $attempt) => stripePaidSession($attempt, ['payment_intent' => ['amount_received' => 1_000]]), 'Amount mismatch'],
    'currency' => [fn (PaymentAttempt $attempt) => stripePaidSession($attempt, ['payment_intent' => ['currency' => 'eur']]), 'Currency mismatch'],
    'reference' => [fn (PaymentAttempt $attempt) => stripePaidSession($attempt, ['client_reference_id' => 'another-reference']), 'does not belong'],
    'metadata reference' => [fn (PaymentAttempt $attempt) => stripePaidSession($attempt, ['metadata' => ['mohar_reference' => 'another-reference']]), 'does not belong'],
    'live object for a test gateway' => [fn (PaymentAttempt $attempt) => stripePaidSession($attempt, ['livemode' => true]), 'does not belong'],
    'refunded before we saw it' => [fn (PaymentAttempt $attempt) => stripePaidSession($attempt, ['payment_intent' => ['latest_charge' => ['amount_refunded' => 500]]]), 'Refunded at the provider'],
]);

test('session states map to the attempt', function (Closure $session, AttemptStatus $expected) {
    [, $invoice, $attempt] = startStripe();
    fakeSessionRetrieve($session($attempt, $invoice->id));

    $this->get(stripeReturn($attempt));

    expect($attempt->fresh()->status)->toBe($expected)
        ->and(Transaction::query()->where('gateway', StripeDriver::KEY)->count())->toBe(0);
})->with([
    'open' => [fn (PaymentAttempt $attempt, int $invoiceId) => stripeCreatedSession($attempt->reference, $invoiceId), AttemptStatus::Pending],
    'expired' => [fn (PaymentAttempt $attempt, int $invoiceId) => stripeCreatedSession($attempt->reference, $invoiceId, ['status' => 'expired']), AttemptStatus::Expired],
    'complete but unpaid (delayed method)' => [fn (PaymentAttempt $attempt) => stripePaidSession($attempt, ['payment_status' => 'unpaid']), AttemptStatus::Pending],
    'paid but the PaymentIntent still processing' => [fn (PaymentAttempt $attempt) => stripePaidSession($attempt, ['payment_intent' => ['status' => 'processing']]), AttemptStatus::Pending],
    'not found' => [fn () => 404, AttemptStatus::Pending],
    'key rejected' => [fn () => 401, AttemptStatus::Ambiguous],
    'Stripe outage' => [fn () => 500, AttemptStatus::Pending],
]);

test('cancelling on Stripe\'s page expires that session so the invoice can be paid another way at once', function () {
    [, $invoice, $attempt] = startStripe();
    Http::fake([
        STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'/expire' => Http::response(stripeCreatedSession($attempt->reference, $invoice->id, ['status' => 'expired'])),
        STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'?*' => Http::response(stripeCreatedSession($attempt->reference, $invoice->id, ['status' => 'expired'])),
    ]);

    $this->get(stripeReturn($attempt, ['cancelled' => 1]))->assertRedirect(route('client.invoices.show', $invoice->id));

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'/expire'
        && $request->header('Idempotency-Key') === ['mohar-expire-'.$attempt->reference]);
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired);

    Http::fake([STRIPE_API.'checkout/sessions' => fn (Request $request) => Http::response(stripeCreatedSession($request['client_reference_id'], $invoice->id, ['id' => 'cs_test_a1MoharSession0002']))]);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => StripeDriver::KEY])->assertRedirect();
    expect(PaymentAttempt::query()->where('invoice_id', $invoice->id)->count())->toBe(2);
});

test('amounts are sent in Stripe units for two- and zero-decimal currencies and refused when inexact', function () {
    Currency::factory()->inr()->create();
    Currency::factory()->create(['code' => 'JPY', 'name' => 'Japanese yen', 'prefix' => '¥', 'decimals' => 0, 'is_default' => false]);
    Currency::factory()->create(['code' => 'KRW', 'name' => 'Won', 'prefix' => '₩', 'decimals' => 2, 'is_default' => false]);
    Currency::factory()->create(['code' => 'ISK', 'name' => 'Króna', 'suffix' => ' kr', 'decimals' => 0, 'is_default' => false]);

    expect(StripeAmount::toStripe(Money::of(123_456, 'INR')))->toBe(123_456)
        ->and(StripeAmount::toStripe(Money::of(5_000, 'JPY')))->toBe(5_000)
        // KRW configured with 2 decimals here: ₩5,000.00 → 5000, but ₩5,000.50 can't be charged.
        ->and(StripeAmount::toStripe(Money::of(500_000, 'KRW')))->toBe(5_000)
        ->and(StripeAmount::toStripe(Money::of(500_050, 'KRW')))->toBeNull()
        ->and(StripeAmount::toStripe(Money::of(7, 'ISK')))->toBe(700)
        ->and(StripeAmount::fromStripe(5_000, 'jpy'))->toBe(5_000)
        ->and(StripeAmount::fromStripe(5_000, 'krw'))->toBe(500_000)
        ->and(StripeAmount::toStripe(Money::of(100, 'UGX')))->toBeNull()
        ->and(StripeAmount::fromStripe('2500', 'usd'))->toBeNull();

    [, $client] = actingAsClient(Client::factory()->create(['currency' => 'JPY']));
    $invoice = issueInvoice($client, [5_000]);
    Http::fake([STRIPE_API.'checkout/sessions' => fn (Request $request) => Http::response(stripeCreatedSession($request['client_reference_id'], $invoice->id, ['currency' => 'jpy']))]);

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => StripeDriver::KEY])->assertRedirect();

    Http::assertSent(fn (Request $request) => $request['line_items'][0]['price_data']['currency'] === 'jpy' && $request['line_items'][0]['price_data']['unit_amount'] === 5000);
});

test('a Checkout url that is not on checkout.stripe.com is never handed to the customer', function () {
    [, $invoice] = usdInvoice();
    Http::fake([STRIPE_API.'checkout/sessions' => fn (Request $request) => Http::response(stripeCreatedSession($request['client_reference_id'], $invoice->id, ['url' => 'https://checkout.stripe.com.evil.test/pay']))]);

    expect(fn () => app(StartHostedPayment::class)->handle($invoice, StripeDriver::KEY))->toThrow(BillingException::class, 'Stripe couldn\'t start the payment.')
        ->and(PaymentAttempt::query()->sole()->status)->toBe(AttemptStatus::Failed);
});

test('Stripe failing or timing out on session create closes the attempt and shows a safe message', function (Closure $answer) {
    Sleep::fake();
    [, $invoice] = usdInvoice();
    Http::fake([STRIPE_API.'checkout/sessions' => $answer]);

    $this->from(route('client.invoices.show', $invoice->id))
        ->post(route('client.invoices.pay', $invoice->id), ['gateway' => StripeDriver::KEY])
        ->assertRedirect(route('client.invoices.show', $invoice->id))
        ->assertSessionHasErrors(['billing' => 'Stripe couldn\'t start the payment. Try again in a few minutes or use another method.']);

    expect(PaymentAttempt::query()->sole())->status->toBe(AttemptStatus::Failed)->review_reason->toBe('Provider refused to start the payment')
        ->and(json_encode(GatewayLog::query()->where('result', 'hosted payment start failed')->sole()->payload))->not->toContain(STRIPE_SECRET);

    // Nothing is left in progress, so the customer can pay another way at once.
    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page->where('payment_in_progress', null));
})->with([
    'Stripe outage' => [fn (Request $request) => Http::response(['error' => ['type' => 'api_error', 'message' => 'Something went wrong on our end.']], 500)],
    'timeout' => [fn (Request $request) => throw new ConnectionException('cURL error 28: Operation timed out')],
]);

test('an invoice in a currency Stripe can\'t charge doesn\'t offer Stripe and a start is refused', function () {
    Currency::factory()->create(['code' => 'XTS', 'name' => 'Test units', 'prefix' => 'T ', 'decimals' => 2, 'is_default' => false]);
    [, $client] = actingAsClient(Client::factory()->create(['currency' => 'XTS']));
    $invoice = issueInvoice($client, [5_000]);

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment.gateways', fn ($gateways) => ! collect($gateways)->contains('key', StripeDriver::KEY)));
    $this->from(route('client.invoices.show', $invoice->id))
        ->post(route('client.invoices.pay', $invoice->id), ['gateway' => StripeDriver::KEY])
        ->assertSessionHasErrors(['billing' => 'Stripe can\'t take payments in XTS.']);

    expect(PaymentAttempt::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('an amount below Stripe\'s minimum is shown disabled with the reason and refused', function () {
    Gateway::factory()->create();
    [, $invoice] = usdInvoice([49]);

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment.gateways', fn ($gateways) => collect($gateways)->firstWhere('key', StripeDriver::KEY)['unavailable_reason'] === 'Stripe needs at least $0.50.')
        ->where('payment.selected_gateway', 'bank_transfer'));
    $this->from(route('client.invoices.show', $invoice->id))
        ->post(route('client.invoices.pay', $invoice->id), ['gateway' => StripeDriver::KEY])
        ->assertSessionHasErrors(['billing' => 'Stripe needs at least $0.50. Choose another way to pay.']);

    expect(PaymentAttempt::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('test mode is never usable in production and keys must match the mode', function () {
    [, $invoice] = usdInvoice();
    app()->detectEnvironment(fn () => 'production');

    // Turning test mode on in production is refused for every gateway with a sandbox setting (SandboxSafetyTest).
    expect(fn () => app(StartHostedPayment::class)->handle($invoice, StripeDriver::KEY))->toThrow(BillingException::class, 'This payment method is not available.');

    $config = app(GatewayManager::class)->config(StripeDriver::KEY);
    expect(app(StripeDriver::class)->testConnection($config))->ok->toBeFalse()->message->toContain('not allowed in production');

    // Live mode with a test key is refused before anything is sent.
    app(ConfigureGateway::class)->handle(StripeDriver::KEY, ['display_name' => 'Stripe', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 0, 'convert_to_currency' => null], ['sandbox' => '0']);
    expect(fn () => app(StartHostedPayment::class)->handle($invoice->fresh(), StripeDriver::KEY))->toThrow(BillingException::class, 'Stripe couldn\'t start the payment.');
    expect(GatewayLog::query()->where('result', 'hosted payment start failed')->latest('id')->first()->payload['error'])->toContain('Live mode needs a live secret key');
    Http::assertNothingSent();
});

test('staff can test the saved keys without them ever reaching the page', function (int $httpStatus, bool $ok, string $message) {
    billingStaff();
    Http::fake([STRIPE_API.'account' => Http::response($httpStatus === 200 ? stripeFixture('account') : stripeFixture('error_invalid_key'), $httpStatus)]);

    $this->get(route('admin.billing.gateways.edit', StripeDriver::KEY))
        ->assertInertia(fn (Assert $page) => $page->where('can_test_connection', true)->where('gateway.sandbox', true)
            ->where('gateway.settings.secret_key', '')->where('gateway.settings.webhook_secret', '')
            ->where('gateway.secrets_set.secret_key', true)->where('gateway.secrets_set.webhook_secret', true)
            ->where('webhook_url', route('billing.webhooks', StripeDriver::KEY))
            ->where('gateway.logo.url', asset('images/gateways/stripe.svg')))
        ->assertDontSee(STRIPE_SECRET)->assertDontSee(STRIPE_WEBHOOK_SECRET);

    $this->post(route('admin.billing.gateways.test', StripeDriver::KEY))->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', $ok ? 'success' : 'error')
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $text) => str_contains($text, $message));
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === STRIPE_API.'account');
    expect(json_encode(GatewayLog::query()->pluck('payload')))->not->toContain(STRIPE_SECRET);
})->with([
    'accepted' => [200, true, 'for Example Hosting'],
    'rejected' => [401, false, 'rejected the secret key'],
]);

test('the connection test catches keys from the other mode without calling Stripe', function (array $config, string $message) {
    $gateway = Gateway::query()->where('key', StripeDriver::KEY)->sole();
    $gateway->fill(['config' => [...$gateway->config, ...$config]]);
    $check = app(StripeDriver::class)->testConnection(app(GatewayManager::class)->config($gateway));

    expect($check)->ok->toBeFalse()->message->toContain($message);
    Http::assertNothingSent();
})->with([
    'live secret in test mode' => [['secret_key' => 'sk_live_51MoharFakeLiveKey000000000000'], 'Test mode needs a test secret key'],
    'live publishable key' => [['publishable_key' => 'pk_live_51MoharFakePublishable0000'], 'publishable key isn\'t a test key'],
    'webhook secret without prefix' => [['webhook_secret' => 'not-a-signing-secret'], 'should start with whsec_'],
]);

test('the pay panel lists the staff-chosen name and names the provider the customer is sent to', function () {
    Gateway::query()->where('key', StripeDriver::KEY)->update(['display_name' => 'Credit or debit card']);
    [, $invoice] = usdInvoice();

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment.gateways', fn ($gateways) => collect($gateways)->contains(fn (array $option) => $option['key'] === StripeDriver::KEY
            && $option['name'] === 'Credit or debit card' && $option['provider'] === 'Stripe' && $option['kind'] === 'redirect')));
});

test('a paid session still processing keeps the invoice locked after the session expired', function (array $session) {
    [, $invoice, $attempt] = startStripe();
    fakeSessionRetrieve(stripePaidSession($attempt, $session));

    $this->get(stripeReturn($attempt));
    $this->travel(40)->minutes();

    expect($attempt->fresh())->status->toBe(AttemptStatus::Pending)->expires_at->toBeNull()
        ->and(fn () => app(StartHostedPayment::class)->handle($invoice->fresh(), StripeDriver::KEY))->toThrow(BillingException::class, 'already in progress')
        ->and(PaymentAttempt::query()->count())->toBe(1);
})->with([
    'complete, unpaid, PaymentIntent processing' => [['payment_status' => 'unpaid', 'payment_intent' => ['status' => 'processing']]],
    'complete, paid, PaymentIntent processing' => [['payment_intent' => ['status' => 'processing']]],
]);

test('a completed session whose payment failed fails the attempt with the reason, so the customer can pay again', function () {
    [, $invoice, $attempt] = startStripe();
    fakeSessionRetrieve(stripePaidSession($attempt, ['payment_status' => 'unpaid', 'payment_intent' => ['status' => 'requires_payment_method',
        'last_payment_error' => ['code' => 'payment_intent_payment_attempt_failed', 'message' => 'The payment failed.']]]));

    $this->get(stripeReturn($attempt));

    expect($attempt->fresh())->status->toBe(AttemptStatus::Failed)->review_reason->toContain('payment_intent_payment_attempt_failed');
    // A new session has a new id (provider references are unique per gateway); the first fake would answer first.
    Http::swap(new HttpFactory);
    Http::fake([STRIPE_API.'checkout/sessions' => fn (Request $request) => Http::response(stripeCreatedSession($request['client_reference_id'], $invoice->id, ['id' => 'cs_test_a1MoharSession0002']))]);
    app(StartHostedPayment::class)->handle($invoice->fresh(), StripeDriver::KEY);
    expect(PaymentAttempt::query()->count())->toBe(2)
        ->and(PaymentAttempt::query()->latest('id')->first()->provider_ref)->toBe('cs_test_a1MoharSession0002');
});

test('the Stripe fee is booked only when its balance transaction is in the charge currency', function (string $feeCurrency, int $fee) {
    [, $invoice, $attempt] = startStripe();
    fakeSessionRetrieve(stripePaidSession($attempt, ['payment_intent' => ['latest_charge' => ['balance_transaction' => ['currency' => $feeCurrency]]]]));

    $this->get(stripeReturn($attempt));

    expect(Transaction::query()->where('gateway', StripeDriver::KEY)->sole()->fee)->toBe($fee);
})->with([
    'charge currency' => ['usd', 103],
    'settlement in another currency' => ['eur', 0],
]);

test('calls to Stripe carry short timeouts: quick for the waiting customer, bounded otherwise', function () {
    $options = [];
    [, $invoice] = usdInvoice();
    Http::fake(function (Request $request, array $sent) use (&$options, $invoice) {
        $options[$request->method().' '.strtok($request->url(), '?')] = [$sent['connect_timeout'] ?? null, $sent['timeout'] ?? null];

        return $request->method() === 'POST'
            ? Http::response(stripeCreatedSession($request['client_reference_id'], $invoice->id))
            : Http::response(stripeCreatedSession(PaymentAttempt::query()->sole()->reference, $invoice->id));
    });

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => StripeDriver::KEY]);
    $this->get(stripeReturn(PaymentAttempt::query()->sole()));

    // Upper bounds, not exact values: about 3 s to connect and 6 s in all while the customer waits on the return, 5 s and
    // 15 s otherwise (payments-stripe.md §8). Zero would mean no timeout at all.
    [$connect, $total] = $options['POST '.STRIPE_API.'checkout/sessions'];
    [$quickConnect, $quickTotal] = $options['GET '.STRIPE_API.'checkout/sessions/'.STRIPE_SESSION];
    expect($quickConnect)->toBeGreaterThan(0)->toBeLessThanOrEqual(3)
        ->and($quickTotal)->toBeGreaterThan(0)->toBeLessThanOrEqual(6)
        ->and($connect)->toBeGreaterThan(0)->toBeLessThanOrEqual(5)
        ->and($total)->toBeGreaterThan(0)->toBeLessThanOrEqual(15);
});
