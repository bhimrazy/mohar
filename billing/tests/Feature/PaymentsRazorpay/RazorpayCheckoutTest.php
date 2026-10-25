<?php

use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsRazorpay\Drivers\RazorpayDriver;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    FakeRazorpay::install();
    razorpayGateway();
});

test('starting a payment creates a payment link in paise with our reference, the static return URL and Basic auth', function () {
    [, $invoice, $attempt] = startRazorpay(113_000);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === RZP_API.'payment_links'
        && $request->header('Authorization') === ['Basic '.base64_encode(RZP_KEY_ID.':'.RZP_KEY_SECRET)]
        && $request['amount'] === 113_000 && $request['currency'] === 'INR' && $request['accept_partial'] === false
        && $request['reference_id'] === $attempt->reference
        && $request['notes'] === ['mohar_reference' => $attempt->reference, 'mohar_invoice_id' => (string) $invoice->id]
        && $request['callback_url'] === route('billing.gateways.return', RazorpayDriver::KEY) && $request['callback_method'] === 'get'
        && $request['notify'] === ['sms' => false, 'email' => false]
        && $request['expire_by'] === now()->addMinutes(30)->getTimestamp());

    expect($attempt)->status->toBe(AttemptStatus::Pending)->provider_ref->toBe(RZP_LINK)->amount->toBe(113_000)->currency->toBe('INR')
        ->and($attempt->expires_at->getTimestamp())->toBe(now()->addMinutes(30)->getTimestamp());
});

test('a signed paid return books nothing by itself; the lookup of the stored link and its payment settles it with the fee', function () {
    Event::fake([InvoicePaid::class]);
    [, $invoice, $attempt] = startRazorpay();

    // Signed and claiming paid, but Razorpay still says the link is open.
    $this->get(razorpayReturn($attempt))->assertRedirect(route('client.invoices.show', $invoice->id));
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)->and($attempt->fresh()->status)->toBe(AttemptStatus::Pending);

    razorpayPaid($attempt);
    $this->travel(2)->minutes();
    $this->get(razorpayReturn($attempt))->assertRedirect(route('client.invoices.show', $invoice->id));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->provider_txn_id->toBe(RZP_PAYMENT)
        ->and(Transaction::query()->where('gateway', RazorpayDriver::KEY)->sole())->gateway_txn_id->toBe(RZP_PAYMENT)->amount_in->toBe(113_000)->fee->toBe(2_668);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'GET' && ! str_contains($request->url(), RZP_LINK) && ! str_contains($request->url(), RZP_PAYMENT));
});

test('a return with a bad signature or only the link id is still matched by provider reference, and never trusted', function () {
    [, $invoice, $attempt] = startRazorpay();
    razorpayPaid($attempt);

    // Forged: no reference, wrong secret. The attempt is found by the link id (#489) and settled by lookup only.
    $this->get(route('billing.gateways.return', [RazorpayDriver::KEY, 'razorpay_payment_link_id' => RZP_LINK, 'razorpay_signature' => 'x']))
        ->assertRedirect(route('client.invoices.show', $invoice->id));

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded);

    $this->get(route('billing.gateways.return', [RazorpayDriver::KEY, 'razorpay_payment_link_id' => 'plink_NeverIssued0001']))
        ->assertRedirect(route('client.invoices.index'))->assertInertiaFlash('toast.type', 'error');
});

test('a lookup that disagrees with the attempt records nothing and goes to staff review', function (array $link, array $payment, string $reason) {
    [, $invoice, $attempt] = startRazorpay();
    razorpayPaid($attempt, $link, $payment);

    $this->get(razorpayReturn($attempt));

    expect(Transaction::query()->where('gateway', RazorpayDriver::KEY)->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toContain($reason);
})->with([
    'payment amount' => [[], ['amount' => 1_000], 'Amount mismatch'],
    'payment currency' => [[], ['currency' => 'USD'], 'Currency mismatch'],
    'link reference' => [['reference_id' => 'another-ref'], [], 'does not belong'],
    'link notes' => [['notes' => []], [], 'does not belong'],
    'link amount' => [['amount' => 100], [], 'does not belong'],
    'payment of another order' => [[], ['order_id' => 'order_SomeoneElse001'], 'does not belong'],
    'two payments on the link' => [['payments' => [['payment_id' => RZP_PAYMENT, 'status' => 'captured'], ['payment_id' => 'pay_MoharPayment00002', 'status' => 'captured']]], [], 'exactly one payment'],
    'refunded before we saw it' => [[], ['amount_refunded' => 500], 'Refunded at the provider'],
]);

test('link and payment states map to the attempt', function (Closure $state, AttemptStatus $expected) {
    [, $invoice, $attempt] = startRazorpay();
    $state($attempt);

    $this->get(razorpayReturn($attempt));

    expect($attempt->fresh()->status)->toBe($expected)->and(Transaction::query()->where('gateway', RazorpayDriver::KEY)->count())->toBe(0);
})->with([
    'open' => [fn () => null, AttemptStatus::Pending],
    'expired' => [fn () => FakeRazorpay::$links[RZP_LINK]['status'] = 'expired', AttemptStatus::Expired],
    'cancelled' => [fn () => FakeRazorpay::$links[RZP_LINK]['status'] = 'cancelled', AttemptStatus::Failed],
    'paid, payment only authorized' => [fn (PaymentAttempt $attempt) => razorpayPaid($attempt, payment: ['status' => 'authorized', 'captured' => false]), AttemptStatus::Pending],
    'key rejected' => [fn () => FakeRazorpay::$links[RZP_LINK] = 401, AttemptStatus::Ambiguous],
    'Razorpay outage' => [fn () => FakeRazorpay::$links[RZP_LINK] = 503, AttemptStatus::Pending],
    'timeout' => [fn () => FakeRazorpay::$links[RZP_LINK] = fn () => throw new ConnectionException('timed out'), AttemptStatus::Pending],
]);

test('the return, the poller and the webhook for one payment, in any order, book one transaction and one InvoicePaid', function () {
    Event::fake([InvoicePaid::class]);
    [, $invoice, $attempt] = startRazorpay();
    razorpayPaid($attempt);
    $event = rzpEvent('payment_link.paid', ['payment_link' => FakeRazorpay::$links[RZP_LINK], 'payment' => FakeRazorpay::$payments[RZP_PAYMENT]]);

    postRazorpayWebhook($event)->assertOk()->assertJson(['result' => 'payment recorded']);
    $this->get(razorpayReturn($attempt));
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments')->assertSuccessful();
    postRazorpayWebhook($event, eventId: 'MoharEvent0002')->assertOk()->assertJson(['result' => 'duplicate']);

    expect(Transaction::query()->where('gateway', RazorpayDriver::KEY)->count())->toBe(1)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

test('continue payment reopens the open link from Razorpay; use another method cancels it first', function () {
    [, $invoice, $attempt] = startRazorpay();

    $this->get(route('client.invoices.attempts.resume', [$invoice->id, $attempt->reference]))->assertRedirect('https://rzp.io/i/MoharLnk');

    FakeRazorpay::$links[RZP_LINK]['short_url'] = 'https://rzp.io.evil.example/i/x';
    $this->from(route('client.invoices.show', $invoice->id))->get(route('client.invoices.attempts.resume', [$invoice->id, $attempt->reference]))
        ->assertRedirect(route('client.invoices.show', $invoice->id))->assertSessionHasErrors('billing');
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);

    $this->post(route('client.invoices.attempts.close', [$invoice->id, $attempt->reference]))->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === RZP_API.'payment_links/'.RZP_LINK.'/cancel');
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Failed)
        ->and(GatewayLog::query()->where('result', 'hosted session close: closed')->count())->toBe(1);
});

test('a key id of the other mode, or test mode in production, is refused before any request', function () {
    $driver = app(RazorpayDriver::class);
    $attempt = new PaymentAttemptRef('inv-1-abc', Money::of(113_000, 'INR'), providerRef: RZP_LINK);
    $liveKey = new GatewayConfig(RazorpayDriver::KEY, 'Razorpay', ['key_id' => 'rzp_live_MoharFakeKey01', 'key_secret' => RZP_KEY_SECRET], sandbox: true);

    expect($driver->lookup($liveKey, $attempt))->status->toBe(LookupResult::PENDING)->raw->toBe(['error' => 'Test mode needs a test key id (rzp_test_…).'])
        ->and($driver->testConnection($liveKey)->ok)->toBeFalse();

    app()->detectEnvironment(fn () => 'production');
    $testMode = new GatewayConfig(RazorpayDriver::KEY, 'Razorpay', ['key_id' => RZP_KEY_ID, 'key_secret' => RZP_KEY_SECRET], sandbox: true);

    expect($driver->lookup($testMode, $attempt)->raw['error'])->toContain('not allowed in production');
    Http::assertNothingSent();
});

test('the admin form shows the webhook and return URLs but never the secrets, and they stay out of the gateway log', function () {
    [, , $attempt] = startRazorpay();
    razorpayPaid($attempt);
    $this->get(razorpayReturn($attempt));
    postRazorpayWebhook(rzpEvent('payment_link.paid', ['payment_link' => FakeRazorpay::$links[RZP_LINK]]))->assertOk();

    billingStaff();
    $page = $this->get(route('admin.billing.gateways.edit', RazorpayDriver::KEY))->assertInertia(fn (Assert $page) => $page
        ->where('webhook_url', route('billing.webhooks', RazorpayDriver::KEY))
        ->where('return_url', route('billing.gateways.return', RazorpayDriver::KEY))
        ->where('gateway.settings.key_id', RZP_KEY_ID)
        ->where('gateway.secrets_set.key_secret', true));

    $stored = json_encode([$page->viewData('page'), GatewayLog::query()->get()->toArray(), PaymentAttempt::query()->get()->toArray(), Transaction::query()->get()->toArray()]);
    expect($stored)->not->toContain(RZP_KEY_SECRET)->not->toContain(RZP_WEBHOOK_SECRET);
});
