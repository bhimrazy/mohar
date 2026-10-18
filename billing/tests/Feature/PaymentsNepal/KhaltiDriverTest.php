<?php

use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Actions\ConfigureGateway;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Actions\StartHostedPayment;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

const KHALTI_SECRET = 'test_secret_key_f59e8b7d18b4499ca40f68195a846e9b';
const KHALTI_INITIATE = 'https://dev.khalti.com/api/v2/epayment/initiate/';
const KHALTI_LOOKUP = 'https://dev.khalti.com/api/v2/epayment/lookup/';

beforeEach(function () {
    Http::preventStrayRequests();
    nepalGateway(KhaltiDriver::KEY, ['sandbox' => '1', 'secret_key' => KHALTI_SECRET]);
});

/**
 * Start a Khalti payment for a fresh NPR invoice; Khalti answers initiate with pidx "PIDX1".
 *
 * @return array{0: Client, 1: Invoice, 2: PaymentAttempt}
 */
function startKhalti(int $amount = 150_000): array
{
    [$client, $invoice] = nprInvoice([$amount]);
    Http::fake([KHALTI_INITIATE => Http::response(['pidx' => 'PIDX1', 'payment_url' => 'https://test-pay.khalti.com/?pidx=PIDX1', 'expires_at' => now()->addHour()->toIso8601String(), 'expires_in' => 3600])]);

    test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertRedirect('https://test-pay.khalti.com/?pidx=PIDX1');

    return [$client, $invoice, PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail()];
}

/**
 * @return array<string, mixed>
 */
function khaltiLookup(string $status, int $amount = 150_000, ?string $transactionId = 'TXN-1'): array
{
    return ['pidx' => 'PIDX1', 'total_amount' => $amount, 'status' => $status, 'transaction_id' => $transactionId, 'fee' => 300, 'refunded' => false];
}

/**
 * The browser coming back from Khalti, with the unsigned query Khalti appends.
 *
 * @param  array<string, string>  $query
 */
function khaltiReturn(PaymentAttempt $attempt, array $query = []): string
{
    return route('billing.gateways.return', [KhaltiDriver::KEY, ...[
        'pidx' => 'PIDX1', 'status' => 'Completed', 'transaction_id' => 'TXN-1', 'amount' => (string) $attempt->amount,
        'total_amount' => (string) $attempt->amount, 'mobile' => '98XXXXX904', 'purchase_order_id' => $attempt->reference,
        'purchase_order_name' => 'Invoice', ...$query,
    ]]);
}

test('initiate sends the amount in paisa with the key header and redirects to Khalti', function () {
    [, $invoice, $attempt] = startKhalti(150_050);

    Http::assertSent(fn (Request $request) => $request->url() === KHALTI_INITIATE
        && $request->header('Authorization') === ['Key '.KHALTI_SECRET]
        && $request['amount'] === 150_050
        && $request['purchase_order_id'] === $attempt->reference
        && $request['return_url'] === route('billing.gateways.return', KhaltiDriver::KEY)
        && $request['website_url'] === config('app.url')
        && $request['purchase_order_name'] === 'Invoice '.$invoice->displayNumber());
    expect($attempt)->status->toBe(AttemptStatus::Pending)->provider_ref->toBe('PIDX1')->amount->toBe(150_050)->currency->toBe('NPR')
        ->and($attempt->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(59);
});

test('a return claiming Completed pays nothing until the lookup confirms it, then the poller settles it', function () {
    Event::fake([InvoicePaid::class]);
    [, $invoice, $attempt] = startKhalti();
    Http::fake([KHALTI_LOOKUP => Http::sequence()->push(khaltiLookup('Pending'))->push(khaltiLookup('Completed'))]);

    $this->get(khaltiReturn($attempt))->assertRedirect(route('client.invoices.show', $invoice->id));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)->and($attempt->fresh()->status)->toBe(AttemptStatus::Pending);

    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    $transaction = Transaction::query()->where('gateway', KhaltiDriver::KEY)->sole();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->provider_txn_id->toBe('TXN-1')
        ->and($transaction)->gateway_txn_id->toBe('TXN-1')->amount_in->toBe(150_000)->fee->toBe(300);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

test('the lookup uses the pidx stored on the attempt, never the one in the return query', function () {
    [, , $attempt] = startKhalti();
    Http::fake([KHALTI_LOOKUP => Http::response(khaltiLookup('Pending'))]);

    $this->get(khaltiReturn($attempt, ['pidx' => 'ATTACKERPIDX']));

    Http::assertSent(fn (Request $request) => $request->url() === KHALTI_LOOKUP && $request['pidx'] === 'PIDX1');
    Http::assertNotSent(fn (Request $request) => $request->url() === KHALTI_LOOKUP && $request['pidx'] === 'ATTACKERPIDX');
});

test('duplicate returns and a racing poller record the payment once', function () {
    Event::fake([InvoicePaid::class]);
    [, $invoice, $attempt] = startKhalti();
    Http::fake([KHALTI_LOOKUP => Http::response(khaltiLookup('Completed'))]);

    $this->get(khaltiReturn($attempt));
    $this->get(khaltiReturn($attempt));
    $this->travel(10)->minutes();
    $this->get(khaltiReturn($attempt));
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->where('gateway', KhaltiDriver::KEY)->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

test('a lookup reporting a different amount records nothing and goes to staff review', function () {
    [, $invoice, $attempt] = startKhalti();
    Http::fake([KHALTI_LOOKUP => Http::response(khaltiLookup('Completed', amount: 1_000))]);

    $this->get(khaltiReturn($attempt, ['amount' => '150000', 'total_amount' => '150000']));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toContain('Amount mismatch');
});

test('Khalti end states close the attempt without paying, including the HTTP 400 ones', function (string $status, int $httpStatus, AttemptStatus $expected) {
    [, $invoice, $attempt] = startKhalti();
    Http::fake([KHALTI_LOOKUP => Http::response(khaltiLookup($status, transactionId: null), $httpStatus)]);

    $this->get(khaltiReturn($attempt, ['status' => $status]));

    expect($attempt->fresh()->status)->toBe($expected)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
})->with([
    'expired' => ['Expired', 400, AttemptStatus::Expired],
    'user canceled' => ['User canceled', 400, AttemptStatus::Failed],
    'refunded before we saw it' => ['Refunded', 200, AttemptStatus::Ambiguous],
    'partially refunded' => ['Partially refunded', 200, AttemptStatus::Ambiguous],
    'unknown status' => ['Something new', 200, AttemptStatus::Ambiguous],
]);

test('an unreachable Khalti keeps the attempt open for the poller', function () {
    Sleep::fake();
    [, , $attempt] = startKhalti();
    $tries = 0;
    Http::fake([KHALTI_LOOKUP => function () use (&$tries) {
        $tries++;

        throw new ConnectionException('timed out');
    }]);

    $this->get(khaltiReturn($attempt));

    expect($attempt->fresh())->status->toBe(AttemptStatus::Pending)->lookup_count->toBe(1)
        ->and($tries)->toBe(3);
});

test('a refused initiate fails the attempt and shows a safe message', function () {
    [, $invoice] = nprInvoice();
    Http::fake([KHALTI_INITIATE => Http::response(['amount' => ['Amount should be greater than Rs. 10, that is 1000 paisa.'], 'error_key' => 'validation_error'], 400)]);

    $this->from(route('client.invoices.show', $invoice->id))
        ->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])
        ->assertSessionHasErrors(['billing' => 'Khalti couldn\'t start the payment. Try again in a few minutes or use another method.']);

    expect(PaymentAttempt::query()->sole()->status)->toBe(AttemptStatus::Failed)
        ->and(GatewayLog::query()->where('result', 'hosted payment start failed')->sole()->payload['error'])->toContain('fields: amount')->not->toContain(KHALTI_SECRET);
});

test('a payment_url outside khalti.com is never followed', function () {
    [, $invoice] = nprInvoice();
    Http::fake([KHALTI_INITIATE => Http::response(['pidx' => 'PIDX1', 'payment_url' => 'https://khalti.com.evil.example/?pidx=PIDX1'])]);

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertSessionHasErrors('billing');

    expect(PaymentAttempt::query()->sole()->status)->toBe(AttemptStatus::Failed);
});

test('Khalti is offered for NPR invoices only and other currencies are refused', function () {
    [, $client] = actingAsClient();
    $usd = issueInvoice($client);

    $this->get(route('client.invoices.show', $usd->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment.gateways', fn ($gateways) => ! collect($gateways)->contains('key', KhaltiDriver::KEY)));
    $this->post(route('client.invoices.pay', $usd->id), ['gateway' => KhaltiDriver::KEY])->assertSessionHasErrors('billing');

    expect(PaymentAttempt::query()->count())->toBe(0);
});

test('Khalti is offered for NPR invoices but refuses amounts below its minimum', function () {
    [, $invoice] = nprInvoice([500]);

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment.gateways', fn ($gateways) => collect($gateways)->contains(fn ($gateway) => $gateway['key'] === KhaltiDriver::KEY && $gateway['kind'] === 'redirect' && $gateway['sandbox'] === true)));
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertSessionHasErrors('billing');

    expect(PaymentAttempt::query()->count())->toBe(0);
});

test('refunds go through the Khalti API, partial ones with the amount in paisa', function () {
    [, $invoice, $attempt] = startKhalti();
    Http::fake([
        KHALTI_LOOKUP => Http::response(khaltiLookup('Completed')),
        'https://dev.khalti.com/api/merchant-transaction/TXN-1/refund/' => Http::response(['detail' => 'Transaction refund successful.']),
    ]);
    $this->get(khaltiReturn($attempt));
    $payment = Transaction::query()->where('gateway', KhaltiDriver::KEY)->sole();

    app(RefundTransaction::class)->handle($payment, 50_000, RefundMode::Gateway);
    app(RefundTransaction::class)->handle($payment->fresh(), 100_000, RefundMode::Gateway);

    $refunds = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/refund/'))->values();
    expect($refunds)->toHaveCount(2)
        ->and($refunds[0][0]->data())->toBe(['amount' => 50_000])
        ->and($refunds[0][0]->header('Authorization'))->toBe(['Key '.KHALTI_SECRET])
        ->and($refunds[1][0]->data())->toBe(['amount' => 100_000])
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);
});

test('a declined refund leaves the payment refundable', function () {
    [, , $attempt] = startKhalti();
    Http::fake([
        KHALTI_LOOKUP => Http::response(khaltiLookup('Completed')),
        'https://dev.khalti.com/api/merchant-transaction/TXN-1/refund/' => Http::response(['detail' => 'Mobile number is required.'], 400),
    ]);
    $this->get(khaltiReturn($attempt));
    $payment = Transaction::query()->where('gateway', KhaltiDriver::KEY)->sole();

    expect(fn () => app(RefundTransaction::class)->handle($payment, 150_000, RefundMode::Gateway))
        ->toThrow(BillingException::class, 'Mobile number is required.');
    expect($payment->fresh()->refundable_remaining)->toBe(150_000);
});

test('live mode talks to khalti.com and sandbox mode cannot be activated in production', function () {
    app(ConfigureGateway::class)->handle(KhaltiDriver::KEY, ['display_name' => 'Khalti', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 0, 'convert_to_currency' => null], ['sandbox' => '0']);
    [, $invoice] = nprInvoice();
    Http::fake(['https://khalti.com/api/v2/epayment/initiate/' => Http::response(['pidx' => 'PIDX9', 'payment_url' => 'https://pay.khalti.com/?pidx=PIDX9'])]);

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertRedirect('https://pay.khalti.com/?pidx=PIDX9');

    app()->detectEnvironment(fn () => 'production');
    expect(fn () => app(ConfigureGateway::class)->handle(KhaltiDriver::KEY, ['display_name' => 'Khalti', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 0, 'convert_to_currency' => null], ['sandbox' => '1']))
        ->toThrow(BillingException::class, 'Sandbox mode can\'t be used in production.');
});

test('a gateway left in sandbox mode is hidden from clients in production', function () {
    [, $invoice] = nprInvoice();
    app()->detectEnvironment(fn () => 'production');

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment.gateways', fn ($gateways) => ! collect($gateways)->contains('key', KhaltiDriver::KEY)));
    expect(fn () => app(StartHostedPayment::class)->handle($invoice, KhaltiDriver::KEY))->toThrow(BillingException::class, 'This payment method is not available.')
        ->and(PaymentAttempt::query()->count())->toBe(0);
});

test('activation requires the secret key', function () {
    Gateway::query()->where('key', KhaltiDriver::KEY)->delete();

    expect(fn () => app(ConfigureGateway::class)->handle('khalti', ['display_name' => 'Khalti', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 0, 'convert_to_currency' => null], ['sandbox' => '1']))
        ->toThrow(BillingException::class, 'Live secret key is required to activate this gateway.');
});

test('staff can test the saved key without it ever reaching the page', function (int $httpStatus, string $toast) {
    billingStaff();
    Http::fake([KHALTI_LOOKUP => Http::response(['detail' => 'irrelevant'], $httpStatus)]);

    $this->get(route('admin.billing.gateways.edit', KhaltiDriver::KEY))
        ->assertInertia(fn (Assert $page) => $page->where('can_test_connection', true)->where('gateway.sandbox', true)->where('gateway.settings.secret_key', '')->where('gateway.secrets_set.secret_key', true))
        ->assertDontSee(KHALTI_SECRET);
    $this->post(route('admin.billing.gateways.test', KhaltiDriver::KEY))->assertRedirect()->assertSessionHas('inertia.flash_data.toast.type', $toast);
    Http::assertSent(fn (Request $request) => $request['pidx'] === 'MoharConnectionTest' && $request->header('Authorization') === ['Key '.KHALTI_SECRET]);
})->with([
    'accepted' => [404, 'success'],
    'rejected' => [401, 'error'],
]);
