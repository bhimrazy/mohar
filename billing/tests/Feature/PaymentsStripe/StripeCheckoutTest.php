<?php

use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Actions\ConfigureGateway;
use App\Modules\Billing\Actions\StartHostedPayment;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Modules\PaymentsStripe\Support\StripeAmount;
use App\Support\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
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

test('test mode is never usable in production and keys must match the mode', function () {
    [, $invoice] = usdInvoice();
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(ConfigureGateway::class)->handle(StripeDriver::KEY, ['display_name' => 'Stripe', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 0, 'convert_to_currency' => null], ['sandbox' => '1']))
        ->toThrow(BillingException::class, 'Sandbox mode can\'t be used in production.')
        ->and(fn () => app(StartHostedPayment::class)->handle($invoice, StripeDriver::KEY))->toThrow(BillingException::class, 'This payment method is not available.');

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
