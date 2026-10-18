<?php

use App\Data\Billing\GatewayConfig;
use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__.'/helpers.php';

const ESEWA_SECRET = 'fake-esewa-secret';
const ESEWA_FORM = 'https://rc-epay.esewa.com.np/api/epay/main/v2/form';
const ESEWA_STATUS = 'https://rc.esewa.com.np/api/epay/transaction/status/*';

beforeEach(function () {
    Http::preventStrayRequests();
    nepalGateway(EsewaDriver::KEY, ['sandbox' => '1', 'product_code' => 'EPAYTEST', 'secret_key' => ESEWA_SECRET]);
});

/**
 * Start an eSewa payment and load the auto-posting checkout page.
 *
 * @return array{0: Invoice, 1: PaymentAttempt, 2: TestResponse<Response>}
 */
function startEsewa(int $amount = 113_050): array
{
    [, $invoice] = nprInvoice([$amount]);
    $checkout = test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->assertRedirect()->headers->get('Location');

    return [$invoice, PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail(), test()->get((string) $checkout)];
}

/**
 * eSewa's success redirect: base64 JSON signed over its signed_field_names (values signed exactly as sent).
 *
 * @param  array<string, string>  $overrides  applied after signing (to tamper)
 * @param  array<string, string>  $signed  values changed before signing
 */
function esewaSuccess(PaymentAttempt $attempt, string $totalAmount = '1,130.5', array $overrides = [], string $secret = ESEWA_SECRET, array $signed = []): string
{
    $payload = [...['transaction_code' => '000ABCD', 'status' => 'COMPLETE', 'total_amount' => $totalAmount, 'transaction_uuid' => $attempt->reference,
        'product_code' => 'EPAYTEST', 'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names'], ...$signed];
    $message = collect(explode(',', $payload['signed_field_names']))->map(fn (string $field) => $field.'='.$payload[$field])->implode(',');
    $payload['signature'] = base64_encode(hash_hmac('sha256', $message, $secret, true));

    return route('billing.gateways.return', [EsewaDriver::KEY, 'data' => base64_encode((string) json_encode([...$payload, ...$overrides]))]);
}

/**
 * @return array<string, mixed>
 */
function esewaStatus(PaymentAttempt $attempt, string $status, float|int $totalAmount = 1130.5, ?string $refId = '0007G36'): array
{
    return ['product_code' => 'EPAYTEST', 'transaction_uuid' => $attempt->reference, 'total_amount' => $totalAmount, 'status' => $status, 'ref_id' => $refId];
}

test('initiate signs total_amount, transaction_uuid and product_code over rupee strings', function () {
    [$invoice, $attempt, $checkout] = startEsewa(113_050);

    $fields = $checkout->inertiaProps('form.fields');
    $message = "total_amount=1130.50,transaction_uuid={$attempt->reference},product_code=EPAYTEST";

    expect($checkout->inertiaProps('form.action'))->toBe(ESEWA_FORM)
        ->and($fields)->toMatchArray([
            'amount' => '1130.50', 'tax_amount' => '0', 'product_service_charge' => '0', 'product_delivery_charge' => '0',
            'total_amount' => '1130.50', 'transaction_uuid' => $attempt->reference, 'product_code' => 'EPAYTEST',
            'success_url' => route('billing.gateways.return', EsewaDriver::KEY),
            'signed_field_names' => 'total_amount,transaction_uuid,product_code',
            'signature' => base64_encode(hash_hmac('sha256', $message, ESEWA_SECRET, true)),
        ])
        ->and($fields['failure_url'])->toContain('ref='.$attempt->reference)
        ->and($checkout->headers->get('Content-Security-Policy'))->toContain("form-action 'self' https://rc-epay.esewa.com.np;")
        ->and($attempt)->amount->toBe(113_050)->currency->toBe('NPR')->status->toBe(AttemptStatus::Pending);
    $this->get(route('client.invoices.show', $invoice->id))->assertOk()->assertDontSee(ESEWA_SECRET);
});

test('signatures match our fixed vectors for whole-rupee requests and comma-formatted responses', function () {
    expect(EsewaDriver::signature(['total_amount' => '1130.00', 'transaction_uuid' => 'inv-1042-a1', 'product_code' => 'EPAYTEST'], EsewaDriver::REQUEST_SIGNED_FIELDS, ESEWA_SECRET))
        ->toBe('LEnZDDJqIu1oogUAvNEgm4Wpg+/6f4vBwccI0ODlknU=')
        ->and(EsewaDriver::signature(
            ['transaction_code' => '000ABCD', 'status' => 'COMPLETE', 'total_amount' => '1,130.0', 'transaction_uuid' => 'inv-1042-a1', 'product_code' => 'EPAYTEST', 'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names'],
            'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names',
            ESEWA_SECRET,
        ))->toBe('C9QSBh3Ovftd7ZkOZRW2g1+iCbF3NtqdeTqsEQ/RNwo=');
});

test('a validly signed COMPLETE return still waits for the status check, then pays exactly once', function () {
    Event::fake([InvoicePaid::class]);
    [$invoice, $attempt] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::sequence()->push(esewaStatus($attempt, 'PENDING', refId: null))->push(esewaStatus($attempt, 'COMPLETE'))]);

    $this->get(esewaSuccess($attempt))->assertRedirect(route('client.invoices.show', $invoice->id));
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);

    $this->travel(6)->minutes();
    $this->get(esewaSuccess($attempt));
    $this->get(esewaSuccess($attempt));
    $this->artisan('billing:reconcile-payments');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->where('gateway', EsewaDriver::KEY)->sole()->gateway_txn_id)->toBe('0007G36')
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://rc.esewa.com.np/')
        && $request['product_code'] === 'EPAYTEST' && $request['total_amount'] === '1130.50' && $request['transaction_uuid'] === $attempt->reference);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

test('the poller settles a payment whose customer never came back', function () {
    [$invoice, $attempt] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::response(esewaStatus($attempt, 'COMPLETE'))]);

    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('a genuine success return verifies and names the attempt', function () {
    [, $attempt] = startEsewa();
    $config = new GatewayConfig(EsewaDriver::KEY, 'eSewa', ['sandbox' => '1', 'product_code' => 'EPAYTEST', 'secret_key' => ESEWA_SECRET], sandbox: true);

    $hint = app(EsewaDriver::class)->parseReturn($config, Illuminate\Http\Request::create(esewaSuccess($attempt)));

    expect($hint)->signatureValid->toBeTrue()->reference->toBe($attempt->reference)->providerRef->toBe('000ABCD')->claimedStatus->toBe('COMPLETE')
        ->and($hint->raw)->not->toHaveKey('signature');
});

test('tampered or foreign-signed returns are flagged and never settle on their own', function (array $overrides, string $secret, array $signed = []) {
    [, $attempt] = startEsewa();
    $request = Illuminate\Http\Request::create(esewaSuccess($attempt, overrides: $overrides, secret: $secret, signed: $signed));
    $config = new GatewayConfig(EsewaDriver::KEY, 'eSewa', ['sandbox' => '1', 'product_code' => 'EPAYTEST', 'secret_key' => ESEWA_SECRET], sandbox: true);

    expect(app(EsewaDriver::class)->parseReturn($config, $request)->signatureValid)->toBeFalse();

    Http::fake([ESEWA_STATUS => Http::response(esewaStatus($attempt, 'NOT_FOUND', refId: null))]);
    $this->get(esewaSuccess($attempt, overrides: $overrides, secret: $secret, signed: $signed));

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending)->and(Transaction::query()->count())->toBe(0);
})->with([
    'amount changed after signing' => [['total_amount' => '1.0'], ESEWA_SECRET],
    'status changed after signing' => [['status' => 'COMPLETE'], ESEWA_SECRET, ['status' => 'PENDING']],
    'transaction code changed after signing' => [['transaction_code' => 'OTHER'], ESEWA_SECRET],
    'signed with another key' => [[], 'attacker-key'],
    'unknown field slipped into the signature' => [['signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names,extra'], ESEWA_SECRET],
]);

test('the status check always carries the stored amount, not the one from the return', function () {
    [, $attempt] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::response(esewaStatus($attempt, 'NOT_FOUND', 1.0, null))]);

    $this->get(esewaSuccess($attempt, overrides: ['total_amount' => '1.0']));

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://rc.esewa.com.np/') && $request['total_amount'] === '1130.50');
});

test('a COMPLETE status for a different amount or transaction goes to staff review', function (array $status) {
    [$invoice, $attempt] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::response([...esewaStatus($attempt, 'COMPLETE'), ...$status])]);

    $this->get(esewaSuccess($attempt));

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0);
})->with([
    'amount' => [['total_amount' => 1.0]],
    'transaction' => [['transaction_uuid' => 'someone-elses-payment']],
    'merchant' => [['product_code' => 'OTHERMERCHANT']],
]);

test('eSewa statuses map to attempt outcomes', function (string $status, AttemptStatus $expected) {
    [$invoice, $attempt] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::response(esewaStatus($attempt, $status))]);

    $this->get(route('billing.gateways.return', [EsewaDriver::KEY, 'ref' => $attempt->reference, 'cancelled' => 1]));

    expect($attempt->fresh()->status)->toBe($expected)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
})->with([
    'cancelled' => ['CANCELED', AttemptStatus::Failed],
    'pending' => ['PENDING', AttemptStatus::Pending],
    'not found (within the TTL)' => ['NOT_FOUND', AttemptStatus::Pending],
    'ambiguous' => ['AMBIGUOUS', AttemptStatus::Ambiguous],
    'full refund before we saw it' => ['FULL_REFUND', AttemptStatus::Ambiguous],
    'partial refund before we saw it' => ['PARTIAL_REFUND', AttemptStatus::Ambiguous],
]);

test('an attempt eSewa never saw expires after its TTL plus the grace period', function () {
    [, $attempt] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::response(esewaStatus($attempt, 'NOT_FOUND', refId: null))]);

    $this->travel(15 + 121)->minutes();
    $this->artisan('billing:reconcile-payments');

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired);
});

test('an eSewa outage keeps the attempt open for the next poll', function () {
    [, $attempt] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::response(['code' => 0, 'error_message' => 'Service is currently unavailable'])]);

    $this->travel(15 + 121)->minutes();
    $this->artisan('billing:reconcile-payments');

    expect($attempt->fresh())->status->toBe(AttemptStatus::Pending)->lookup_count->toBe(1)->raw->toMatchArray(['error' => 'error_message: Service is currently unavailable; code: 0']);
});

test('eSewa payments are refunded in the portal, not through the API', function () {
    [, $attempt] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::response(esewaStatus($attempt, 'COMPLETE'))]);
    $this->get(esewaSuccess($attempt));
    $payment = Transaction::query()->where('gateway', EsewaDriver::KEY)->sole();

    expect(fn () => app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway))
        ->toThrow(BillingException::class, 'eSewa can\'t refund automatically. Record the refund instead.');

    app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::RecordOnly);
    expect($payment->fresh()->refundable_remaining)->toBe(112_050);
});

test('the connection test books a signed UAT session to check the code and key', function (int $httpStatus, array $headers, string $toast) {
    billingStaff();
    Http::fake([ESEWA_FORM => Http::response($httpStatus === 400 ? ['code' => 'ES104', 'message' => 'Invalid payload signature.'] : '', $httpStatus, $headers)]);

    $this->get(route('admin.billing.gateways.edit', EsewaDriver::KEY))
        ->assertInertia(fn (Assert $page) => $page->where('can_test_connection', true)->where('gateway.settings.product_code', 'EPAYTEST')->where('gateway.settings.secret_key', ''))
        ->assertDontSee(ESEWA_SECRET);
    $this->post(route('admin.billing.gateways.test', EsewaDriver::KEY))->assertSessionHas('inertia.flash_data.toast.type', $toast);

    Http::assertSent(fn (Request $request) => $request->url() === ESEWA_FORM && $request['signature'] === EsewaDriver::signature($request->data(), EsewaDriver::REQUEST_SIGNED_FIELDS, ESEWA_SECRET));
})->with([
    'accepted' => [302, ['Location' => 'https://rc-epay.esewa.com.np/epay?bookingId=abc'], 'success'],
    'rejected' => [400, [], 'error'],
]);

test('the provider form is served once, so a refresh never re-posts the signed form', function () {
    [$invoice, $attempt, $checkout] = startEsewa();
    $checkout->assertOk();

    $this->get(route('client.invoices.checkout', [$invoice->id, $attempt->reference]))
        ->assertRedirect(route('client.invoices.show', $invoice->id))
        ->assertSessionHas('inertia.flash_data.toast.message', 'This payment session has ended. Start the payment again.');
});

test('a sandbox attempt opened before switching to production fails closed instead of serving the UAT form', function () {
    [, $invoice] = nprInvoice();
    $checkoutUrl = (string) $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->headers->get('Location');

    app()->detectEnvironment(fn () => 'production');
    $response = $this->get($checkoutUrl)
        ->assertRedirect(route('client.invoices.show', $invoice->id))
        ->assertSessionHas('inertia.flash_data.toast.message', 'This payment method is no longer available. Choose another way to pay.');

    expect($response->headers->get('Content-Security-Policy'))->not->toContain('esewa');
});

test('a refused status check sends the attempt to staff review', function () {
    [, $attempt] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::response(['message' => 'Forbidden'], 403)]);

    $this->get(esewaSuccess($attempt));

    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toContain('eSewa refused the status check (HTTP 403)');
});
