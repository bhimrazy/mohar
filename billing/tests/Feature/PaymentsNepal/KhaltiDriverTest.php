<?php

use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Actions\ConfigureGateway;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Actions\StartHostedPayment;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\GatewayManager;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use App\Modules\PaymentsNepal\Support\ProviderHttp;
use App\Support\Dashboard\Dashboard;
use App\Support\Money;
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

test('an unreachable Khalti costs the waiting customer one quick try and leaves retries to the poller', function () {
    Sleep::fake();
    [, $invoice, $attempt] = startKhalti();
    $tries = 0;
    Http::fake([KHALTI_LOOKUP => function () use (&$tries) {
        $tries++;

        throw new ConnectionException('timed out');
    }]);

    $this->get(khaltiReturn($attempt))
        ->assertRedirect(route('client.invoices.show', $invoice->id))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Your payment is being confirmed. We\'ll update the invoice shortly.');
    expect($tries)->toBe(1);

    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments');

    expect($attempt->fresh())->status->toBe(AttemptStatus::Pending)->lookup_count->toBe(2)
        ->and($tries)->toBe(1 + 3);
});

test('a rejected key during lookup sends the attempt to staff review, and it settles once the key is fixed', function () {
    [, $invoice, $attempt] = startKhalti();
    Http::fake([KHALTI_LOOKUP => Http::sequence()->push(['detail' => 'Invalid token.'], 401)->push(khaltiLookup('Completed'))]);

    $this->get(khaltiReturn($attempt));

    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toContain('Khalti rejected the secret key (HTTP 401)');
    $this->travel(31)->minutes();
    $todo = collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.payment_review');
    expect($todo['count'])->toBe(1);

    $this->artisan('billing:reconcile-payments');

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
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

test('a payment_url outside khalti.com is never followed', function (string $paymentUrl) {
    [, $invoice] = nprInvoice();
    Http::fake([KHALTI_INITIATE => Http::response(['pidx' => 'PIDX1', 'payment_url' => $paymentUrl])]);

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertSessionHasErrors('billing');

    expect(PaymentAttempt::query()->sole()->status)->toBe(AttemptStatus::Failed);
})->with([
    'look-alike domain' => 'https://khalti.com.evil.example/?pidx=PIDX1',
    'backslash host' => 'https://evil.com\\.khalti.com/?pidx=PIDX1',
    'backslash userinfo' => 'https://evil.com\\@pay.khalti.com/?pidx=PIDX1',
    'userinfo' => 'https://evil.com@pay.khalti.com/?pidx=PIDX1',
    'explicit port' => 'https://pay.khalti.com:8443/?pidx=PIDX1',
    'plain http' => 'http://pay.khalti.com/?pidx=PIDX1',
    'whitespace' => 'https://pay.khalti.com /?pidx=PIDX1',
]);

test('a payment_url on khalti.com or a subdomain is followed whatever its case', function () {
    [, $invoice] = nprInvoice();
    Http::fake([KHALTI_INITIATE => Http::response(['pidx' => 'PIDX1', 'payment_url' => 'https://Pay.Khalti.com/?pidx=PIDX1'])]);

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertRedirect('https://Pay.Khalti.com/?pidx=PIDX1');
});

test('Khalti is offered for NPR invoices only and other currencies are refused', function () {
    [, $client] = actingAsClient();
    $usd = issueInvoice($client);

    $this->get(route('client.invoices.show', $usd->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment.gateways', fn ($gateways) => ! collect($gateways)->contains('key', KhaltiDriver::KEY)));
    $this->post(route('client.invoices.pay', $usd->id), ['gateway' => KhaltiDriver::KEY])->assertSessionHasErrors('billing');

    expect(PaymentAttempt::query()->count())->toBe(0);
});

test('partial refunds stay off by default while full refunds go through the API', function () {
    [, $invoice, $attempt] = startKhalti();
    Http::fake([
        KHALTI_LOOKUP => Http::response(khaltiLookup('Completed')),
        'https://dev.khalti.com/api/merchant-transaction/TXN-1/refund/' => Http::response(['detail' => 'Transaction refund successful.']),
    ]);
    $this->get(khaltiReturn($attempt));
    $payment = Transaction::query()->where('gateway', KhaltiDriver::KEY)->sole();

    expect(app(KhaltiDriver::class)->supports(KhaltiDriver::PARTIAL_REFUND))->toBeFalse()
        ->and(fn () => app(RefundTransaction::class)->handle($payment, 50_000, RefundMode::Gateway))
        ->toThrow(BillingException::class, 'Khalti only refunds the full amount automatically. Record partial refunds instead.');

    app(RefundTransaction::class)->handle($payment->fresh(), 150_000, RefundMode::Gateway);

    $refunds = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/refund/'))->values();
    expect($refunds)->toHaveCount(1)
        ->and($refunds[0][0]->data())->toBe([])
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);
});

test('with partial refunds turned on, the refund amount is sent in paisa', function () {
    config(['payments_nepal.khalti.partial_refunds' => true]);
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

test('a Khalti server error or a timeout keeps the refund pending; the amount is held until staff settle it', function (Closure $response) {
    [, , $attempt] = startKhalti();
    Http::fake([
        KHALTI_LOOKUP => Http::response(khaltiLookup('Completed')),
        'https://dev.khalti.com/api/merchant-transaction/TXN-1/refund/' => $response,
    ]);
    $this->get(khaltiReturn($attempt));
    $payment = Transaction::query()->where('gateway', KhaltiDriver::KEY)->sole();

    $pending = app(RefundTransaction::class)->handle($payment, 150_000, RefundMode::Gateway);

    expect($pending->status->value)->toBe('pending')->and($payment->fresh()->refundable_remaining)->toBe(0);
    expect(fn () => app(RefundTransaction::class)->handle($payment->fresh(), 1, RefundMode::Gateway))->toThrow(BillingException::class);
    expect(app(RefundTransaction::class)->resolve($pending, false)->status->value)->toBe('failed')
        ->and($payment->fresh()->refundable_remaining)->toBe(150_000);
})->with([
    'server error' => [fn () => Http::response(['detail' => 'boom'], 500)],
    'timeout' => [fn () => throw new ConnectionException('timed out')],
]);

test('a Khalti refund that never left (a non-NPR amount) is a definite decline', function () {
    $result = app(KhaltiDriver::class)->refund(
        new GatewayConfig(KhaltiDriver::KEY, 'Khalti'),
        new RefundRequest('TXN-1', Money::of(100, 'USD'), Money::of(100, 'USD'), reference: '1'),
    );

    expect($result->status)->toBe('declined');
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

test('live mode talks to khalti.com', function () {
    app(ConfigureGateway::class)->handle(KhaltiDriver::KEY, ['display_name' => 'Khalti', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 0, 'convert_to_currency' => null], ['sandbox' => '0']);
    [, $invoice] = nprInvoice();
    Http::fake(['https://khalti.com/api/v2/epayment/initiate/' => Http::response(['pidx' => 'PIDX9', 'payment_url' => 'https://pay.khalti.com/?pidx=PIDX9'])]);

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertRedirect('https://pay.khalti.com/?pidx=PIDX9');
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
        ->toThrow(BillingException::class, 'Secret key is required to activate this gateway.');
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
    'accepted (not found)' => [404, 'success'],
    'accepted (validation error)' => [400, 'success'],
    'rejected' => [401, 'error'],
]);

test('an Initiated payment stays pending until Khalti reports an outcome', function () {
    [, $invoice, $attempt] = startKhalti();
    Http::fake([KHALTI_LOOKUP => Http::response(khaltiLookup('Initiated', transactionId: null))]);

    $this->get(khaltiReturn($attempt, ['status' => 'Initiated']));

    expect($attempt->fresh())->status->toBe(AttemptStatus::Pending)->lookup_count->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

test('the payer mobile number from the return is masked to its last three digits', function () {
    $config = app(GatewayManager::class)->config(KhaltiDriver::KEY);
    $request = Illuminate\Http\Request::create('/?pidx=PIDX1&status=Completed&purchase_order_id=abcdefghijklmnopqrstuvwxyz&mobile=9800000904');

    $hint = app(KhaltiDriver::class)->parseReturn($config, $request);

    expect($hint->raw['mobile'])->toBe('*******904')
        ->and(json_encode($hint->raw))->not->toContain('9800000904')
        ->and(ProviderHttp::maskMobile('98-0000-0904'))->toBe('*******904')
        ->and(ProviderHttp::maskMobile(''))->toBeNull();
});

/**
 * Issue #130 (matrix PG-19 / M-01): Khalti didn't know the pidx (HTTP 404) through the TTL plus grace, so the attempt
 * expired; later its lookup answers $later. An hour on, both the return route and the sweep look it up again.
 *
 * @return array{0: Invoice, 1: PaymentAttempt}
 */
function expiredKhalti(string $later = 'Completed'): array
{
    [, $invoice, $attempt] = startKhalti();
    Http::fake([KHALTI_LOOKUP => Http::sequence()->push(['detail' => 'Not found.', 'error_key' => 'validation_error'], 404)
        ->whenEmpty(Http::response(khaltiLookup($later, transactionId: $later === 'Completed' ? 'TXN-1' : null), $later === 'Completed' ? 200 : 400))]);

    test()->travel(60 + 121)->minutes();
    test()->artisan('billing:reconcile-payments');
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired);
    test()->travel(61)->minutes();

    return [$invoice, $attempt->fresh()];
}

test('a payment Khalti completes after the attempt expired is booked on the unpaid invoice and flagged for staff', function (Closure $check) {
    [$invoice, $attempt] = expiredKhalti();

    $check($attempt);

    expect(Transaction::query()->where('gateway', KhaltiDriver::KEY)->sole())->amount_in->toBe(150_000)->gateway_txn_id->toBe('TXN-1')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->late_payment_at->not->toBeNull()
        ->and(ActivityLog::query()->where('event', 'billing.payment.late')->sole()->properties)->toMatchArray(['closed_as' => 'expired', 'applied' => 150_000, 'credited' => 0])
        ->and(collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.late_payments')['count'])->toBe(1);
})->with([
    'customer returns' => [fn (PaymentAttempt $attempt) => test()->get(khaltiReturn($attempt))->assertSessionHas('inertia.flash_data.toast.type', 'success')],
    'verify sweep' => [fn (PaymentAttempt $attempt) => test()->artisan('billing:reconcile-payments')],
]);

test('a late Khalti payment on an invoice already paid becomes NPR account credit', function () {
    [$invoice] = expiredKhalti();
    payInvoice($invoice, 150_000, 'bank-ref-1');

    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->where('gateway', KhaltiDriver::KEY)->sole()->amount_in)->toBe(150_000)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(app(CreditLedgerService::class)->balance($invoice->client_id, 'NPR')->amount)->toBe(150_000);
});

test('a late Khalti payment larger than the remaining balance pays it and credits the rest', function () {
    [$invoice, $attempt] = expiredKhalti();
    payInvoice($invoice, 50_000, 'bank-ref-2');

    $this->get(khaltiReturn($attempt));

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->balance->toBe(0)
        ->and(app(CreditLedgerService::class)->balance($invoice->client_id, 'NPR')->amount)->toBe(50_000);
});

test('repeated returns and sweeps book a late Khalti payment once', function () {
    [$invoice, $attempt] = expiredKhalti();

    $this->get(khaltiReturn($attempt));
    $this->artisan('billing:reconcile-payments');
    $this->travel(2)->hours();
    $this->get(khaltiReturn($attempt));
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->where('gateway', KhaltiDriver::KEY)->count())->toBe(1)
        ->and(ActivityLog::query()->where('event', 'billing.payment.late')->count())->toBe(1)
        ->and(CreditLedgerEntry::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('an expired Khalti attempt whose lookup still says not paid stays closed', function (string $later) {
    [$invoice, $attempt] = expiredKhalti($later);

    $this->get(khaltiReturn($attempt));
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->count())->toBe(0)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Expired)->late_payment_at->toBeNull()->lookup_count->toBe($attempt->lookup_count + 1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
})->with(['Expired', 'User canceled']);
