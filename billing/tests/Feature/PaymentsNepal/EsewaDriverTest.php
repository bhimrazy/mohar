<?php

use App\Data\Billing\GatewayConfig;
use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use App\Support\Dashboard\Dashboard;
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
 * Start another eSewa payment for the same invoice and load its checkout page.
 *
 * @return TestResponse<Response>
 */
function startEsewaAgain(Invoice $invoice): TestResponse
{
    $checkout = test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->assertRedirect()->headers->get('Location');

    return test()->get((string) $checkout)->assertOk();
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
 * A body captured from eSewa UAT on 2026-10-21 (tests/Fixtures/Esewa), with fields replaced.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function esewaFixture(string $name, array $overrides = []): array
{
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/Esewa/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);

    return [...$fixture, ...$overrides];
}

/**
 * A status-check answer in the captured shape, for this attempt.
 *
 * @return array<string, mixed>
 */
function esewaStatus(PaymentAttempt $attempt, string $status, float|int $totalAmount = 1130.5, ?string $refId = '0007G36'): array
{
    return esewaFixture('status_complete', ['transaction_uuid' => $attempt->reference, 'total_amount' => $totalAmount, 'status' => $status, 'ref_id' => $refId]);
}

/**
 * The failure_url eSewa sends the customer back to, unchanged, after a confirmed cancel (UAT, 2026-10-21).
 *
 * @param  TestResponse<Response>  $checkout
 */
function esewaCancelUrl(TestResponse $checkout): string
{
    return (string) $checkout->inertiaProps('form.fields')['failure_url'];
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

test('a validly signed COMPLETE return still waits for the status check, then the poller pays it once', function () {
    Event::fake([InvoicePaid::class]);
    [$invoice, $attempt] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::sequence()->push(esewaStatus($attempt, 'PENDING', refId: null))->push(esewaStatus($attempt, 'COMPLETE'))]);

    $this->get(esewaSuccess($attempt))->assertRedirect(route('client.invoices.show', $invoice->id));
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);

    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->where('gateway', EsewaDriver::KEY)->sole()->gateway_txn_id)->toBe('0007G36')
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://rc.esewa.com.np/')
        && $request['product_code'] === 'EPAYTEST' && $request['total_amount'] === '1130.50' && $request['transaction_uuid'] === $attempt->reference);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
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
    'cancelled (documented, not seen in UAT)' => ['CANCELED', AttemptStatus::Failed],
    'pending' => ['PENDING', AttemptStatus::Pending],
    'not found after the cancel' => ['NOT_FOUND', AttemptStatus::Failed],
    'ambiguous' => ['AMBIGUOUS', AttemptStatus::Ambiguous],
    'full refund before we saw it' => ['FULL_REFUND', AttemptStatus::Ambiguous],
    'partial refund before we saw it' => ['PARTIAL_REFUND', AttemptStatus::Ambiguous],
]);

test('a NOT_FOUND without a cancel return pays nothing and ends the attempt only once the form had time to reach eSewa', function () {
    [$invoice, $attempt] = startEsewa(10_000);
    Http::fake([ESEWA_STATUS => Http::response(esewaFixture('status_not_found_cancelled', ['transaction_uuid' => $attempt->reference]))]);

    $this->travel(2)->minutes();
    $this->get(route('billing.gateways.return', [EsewaDriver::KEY, 'ref' => $attempt->reference]));
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->assertSessionHasErrors('billing');

    $this->travel(5)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);

    expect($attempt->fresh())->status->toBe(AttemptStatus::Expired)->lookups_closed_at->toBeNull()->review_reason->toBe('Abandoned: the provider has no payable session')
        ->and(Transaction::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->assertRedirect(route('client.invoices.checkout', [$invoice->id, PaymentAttempt::query()->latest('id')->firstOrFail()->reference]));
});

test('a customer who cancels on eSewa can pay again at once, under a new transaction uuid', function (bool $polledOnEsewaPage) {
    [$invoice, $attempt, $checkout] = startEsewa(10_000);
    $cancelled = esewaFixture('status_not_found_cancelled', ['transaction_uuid' => $attempt->reference]);

    if ($polledOnEsewaPage) {
        // The poller saw the booking while the customer was still on eSewa's page; the cancel comes 30 seconds later.
        Http::fake([ESEWA_STATUS => Http::sequence()->push(esewaFixture('status_pending', ['transaction_uuid' => $attempt->reference, 'total_amount' => 100.0]))->push($cancelled)]);
        $this->artisan('billing:reconcile-payments', ['--only' => 'open']);
        expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
        $this->travel(30)->seconds();
    } else {
        Http::fake([ESEWA_STATUS => Http::response($cancelled)]);
    }

    $this->get(esewaCancelUrl($checkout))
        ->assertRedirect(route('client.invoices.show', $invoice->id))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Payment cancelled. You can try again or choose another way to pay.');

    expect($attempt->fresh())->status->toBe(AttemptStatus::Failed)->lookups_closed_at->toBeNull()->review_reason->toBe('Cancelled by the customer: the provider has no payable session')
        ->and(Transaction::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
    // The invoice offers "Pay a different way" at once, with the "don't pay again" warning, while the old form could
    // still be posted (#123).
    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment_in_progress.status', 'failed')
        ->where('payment_in_progress.can_pay_another_way', true)
        ->where('payment_in_progress.can_close', false)
        ->whereNot('payment', null));

    // eSewa answers "Duplicate transaction UUID." to a reused uuid (UAT), so the retry must carry a new one.
    $retry = startEsewaAgain($invoice);
    expect($retry->inertiaProps('form.fields.transaction_uuid'))->not->toBe($attempt->reference)
        ->and(PaymentAttempt::query()->count())->toBe(2);
})->with([
    'straight back from eSewa' => false,
    'after a poll while on eSewa\'s page' => true,
]);

test('a booked eSewa session keeps the invoice locked for eSewa\'s whole booking window', function () {
    config(['payments_nepal.esewa.attempt_ttl_minutes' => 15]);
    $booking = esewaFixture('booking_window');
    $windowMinutes = ($booking['product_details']['bookingExpiryTime'] - $booking['form_posted_at_ms']) / 60_000;
    [$invoice, $attempt, $checkout] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::response(esewaFixture('status_pending', ['transaction_uuid' => $attempt->reference]))]);

    expect($windowMinutes)->toBeGreaterThan(49)->toBeLessThan(51)
        ->and($attempt->created_at->diffInMinutes($attempt->expires_at))->toBeGreaterThanOrEqual($windowMinutes + 5);

    // Back through the failure URL while eSewa still holds a payable booking: a hint, and PENDING keeps the lock.
    $this->travel((int) floor($windowMinutes) - 1)->minutes();
    $this->get(esewaCancelUrl($checkout))->assertSessionHas('inertia.flash_data.toast.type', 'info');
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page->where('payment_in_progress.can_pay_another_way', false));
    $this->from(route('client.invoices.show', $invoice->id))->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])
        ->assertSessionHasErrors(['billing' => 'A payment with Esewa is already in progress for this invoice. Check its status before paying again.']);

    $this->travel(7)->minutes();
    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page->where('payment_in_progress.can_pay_another_way', true));
});

test('use another method is neither offered nor carried out for eSewa, whose NOT_FOUND does not close the form', function () {
    [$invoice, $attempt] = startEsewa(10_000);
    Http::fake([ESEWA_STATUS => Http::response(esewaFixture('status_not_found_cancelled', ['transaction_uuid' => $attempt->reference]))]);

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment_in_progress.status', 'pending')
        ->where('payment_in_progress.can_close', false));

    // A direct POST is refused before any lookup: no settle, no state change, the invoice stays locked.
    $this->from(route('client.invoices.show', $invoice->id))->post(route('client.invoices.attempts.close', [$invoice->id, $attempt->reference]))
        ->assertRedirect(route('client.invoices.show', $invoice->id))
        ->assertSessionHasErrors(['billing' => 'This payment can\'t be closed from here. Check its status, or wait until the payment page times out.']);

    Http::assertNothingSent();
    expect($attempt->fresh())->status->toBe(AttemptStatus::Pending)->lookup_count->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
});

test('an attempt in staff review is not ended by a later NOT_FOUND', function () {
    [, $attempt, $checkout] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::sequence()
        ->push(esewaFixture('status_complete', ['transaction_uuid' => $attempt->reference, 'total_amount' => 1.0]))
        ->push(esewaFixture('status_not_found_cancelled', ['transaction_uuid' => $attempt->reference]))]);

    $this->get(esewaSuccess($attempt));
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);
    $this->travel(2)->minutes();
    $this->get(esewaCancelUrl($checkout));

    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->lookup_count->toBe(2)
        ->and(Transaction::query()->count())->toBe(0);
});

test('a stale eSewa form paid after the customer cancelled and paid another way goes to credit once and is flagged', function () {
    [$invoice, $attempt, $checkout] = startEsewa(10_000);
    Http::fake([ESEWA_STATUS => Http::sequence()->push(esewaFixture('status_not_found_cancelled', ['transaction_uuid' => $attempt->reference]))
        ->whenEmpty(Http::response(esewaFixture('status_complete', ['transaction_uuid' => $attempt->reference])))]);

    $this->get(esewaCancelUrl($checkout));
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Failed);
    payInvoice($invoice, 10_000, 'bank-ref-3');

    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments');
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->where('gateway', EsewaDriver::KEY)->sole())->amount_in->toBe(10_000)->gateway_txn_id->toBe('0001FXT')
        ->and(app(CreditLedgerService::class)->balance($invoice->client_id, 'NPR')->amount)->toBe(10_000)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->late_payment_at->not->toBeNull()
        ->and(ActivityLog::query()->where('event', 'billing.payment.late')->sole()->properties)->toMatchArray(['closed_as' => 'failed', 'credited' => 10_000]);
});

test('an eSewa outage keeps the attempt open for the next poll', function () {
    [, $attempt] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::response(['code' => 0, 'error_message' => 'Service is currently unavailable'])]);

    $this->travel(15 + 121)->minutes();
    $this->artisan('billing:reconcile-payments');

    expect($attempt->fresh())->status->toBe(AttemptStatus::Pending)->lookup_count->toBe(1)->raw->toMatchArray(['error' => 'error_message: Service is currently unavailable; code: 0']);
});

test('eSewa is offered for NPR invoices only and other currencies are refused', function () {
    [, $client] = actingAsClient();
    $usd = issueInvoice($client);

    $this->get(route('client.invoices.show', $usd->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment.gateways', fn ($gateways) => ! collect($gateways)->contains('key', EsewaDriver::KEY)));
    $this->from(route('client.invoices.show', $usd->id))
        ->post(route('client.invoices.pay', $usd->id), ['gateway' => EsewaDriver::KEY])
        ->assertSessionHasErrors(['billing' => 'Esewa can\'t take payments in USD.']);

    expect(PaymentAttempt::query()->count())->toBe(0);
});

test('an amount below eSewa\'s minimum is shown disabled with the reason and refused', function () {
    nepalGateway('bank_transfer', []);
    [, $invoice] = nprInvoice([50]);

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment.gateways', fn ($gateways) => collect($gateways)->firstWhere('key', EsewaDriver::KEY)['unavailable_reason'] === 'Esewa needs at least Rs 1.00.')
        ->where('payment.selected_gateway', 'bank_transfer'));
    $this->from(route('client.invoices.show', $invoice->id))
        ->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])
        ->assertSessionHasErrors(['billing' => 'Esewa needs at least Rs 1.00. Choose another way to pay.']);

    expect(PaymentAttempt::query()->count())->toBe(0);
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

test('the connection test books a signed UAT session to check the code and key', function (int $httpStatus, ?string $fixture, array $headers, string $toast, string $message) {
    billingStaff();
    Http::fake([ESEWA_FORM => Http::response($fixture !== null ? esewaFixture($fixture) : '', $httpStatus, $headers)]);

    $this->get(route('admin.billing.gateways.edit', EsewaDriver::KEY))
        ->assertInertia(fn (Assert $page) => $page->where('can_test_connection', true)->where('gateway.settings.product_code', 'EPAYTEST')->where('gateway.settings.secret_key', ''))
        ->assertDontSee(ESEWA_SECRET);
    $this->post(route('admin.billing.gateways.test', EsewaDriver::KEY))->assertSessionHas('inertia.flash_data.toast.type', $toast)
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $text) => str_contains($text, $message));

    Http::assertSent(fn (Request $request) => $request->url() === ESEWA_FORM && $request['signature'] === EsewaDriver::signature($request->data(), EsewaDriver::REQUEST_SIGNED_FIELDS, ESEWA_SECRET));
})->with([
    'accepted' => [302, null, ['Location' => 'https://rc-epay.esewa.com.np/epay?bookingId=abc'], 'success', 'accepted the product code and secret key'],
    'bad signature (ES104)' => [400, 'form_invalid_signature', [], 'error', 'rejected the product code or secret key (sandbox mode): message: Invalid payload signature.; code: ES104'],
    'duplicate uuid is not blamed on the credentials' => [400, 'form_duplicate_uuid', [], 'error', 'refused the test booking (sandbox mode): error_message: Duplicate transaction UUID.; code: 0'],
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

/**
 * Issue #130 (matrix PG-19 / M-01): eSewa never saw the payment within the TTL plus grace, so the attempt expired;
 * later its status check answers $later. An hour on, both the return route and the sweep look it up again.
 *
 * @return array{0: Invoice, 1: PaymentAttempt}
 */
function expiredEsewa(string $later = 'COMPLETE'): array
{
    [$invoice, $attempt] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::sequence()->push(esewaStatus($attempt, 'NOT_FOUND', refId: null))
        ->whenEmpty(Http::response(esewaStatus($attempt, $later, refId: $later === 'COMPLETE' ? '0007G36' : null)))]);

    test()->travel(15 + 121)->minutes();
    test()->artisan('billing:reconcile-payments');
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired);
    test()->travel(61)->minutes();

    return [$invoice, $attempt->fresh()];
}

test('a payment eSewa completes after the attempt expired is booked on the unpaid invoice and flagged for staff', function (Closure $check) {
    [$invoice, $attempt] = expiredEsewa();

    $check($attempt);

    expect(Transaction::query()->where('gateway', EsewaDriver::KEY)->sole())->amount_in->toBe(113_050)->gateway_txn_id->toBe('0007G36')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->late_payment_at->not->toBeNull()
        ->and(ActivityLog::query()->where('event', 'billing.payment.late')->sole()->properties)->toMatchArray(['closed_as' => 'expired', 'applied' => 113_050, 'credited' => 0])
        ->and(collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.late_payments')['count'])->toBe(1);
})->with([
    'customer returns' => [fn (PaymentAttempt $attempt) => test()->get(esewaSuccess($attempt))->assertSessionHas('inertia.flash_data.toast.type', 'success')],
    'verify sweep' => [fn (PaymentAttempt $attempt) => test()->artisan('billing:reconcile-payments')],
]);

test('a late eSewa payment on an invoice already paid becomes NPR account credit', function () {
    [$invoice] = expiredEsewa();
    payInvoice($invoice, 113_050, 'bank-ref-1');

    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->where('gateway', EsewaDriver::KEY)->sole()->amount_in)->toBe(113_050)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(app(CreditLedgerService::class)->balance($invoice->client_id, 'NPR')->amount)->toBe(113_050);
});

test('a late eSewa payment larger than the remaining balance pays it and credits the rest', function () {
    [$invoice, $attempt] = expiredEsewa();
    payInvoice($invoice, 13_050, 'bank-ref-2');

    $this->get(esewaSuccess($attempt));

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->balance->toBe(0)
        ->and(app(CreditLedgerService::class)->balance($invoice->client_id, 'NPR')->amount)->toBe(13_050);
});

test('repeated returns and sweeps book a late eSewa payment once', function () {
    [$invoice, $attempt] = expiredEsewa();

    $this->get(esewaSuccess($attempt));
    $this->artisan('billing:reconcile-payments');
    $this->travel(2)->hours();
    $this->get(esewaSuccess($attempt));
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->where('gateway', EsewaDriver::KEY)->count())->toBe(1)
        ->and(ActivityLog::query()->where('event', 'billing.payment.late')->count())->toBe(1)
        ->and(CreditLedgerEntry::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('an expired eSewa attempt whose status check still says not paid stays closed', function (string $later) {
    [$invoice, $attempt] = expiredEsewa($later);

    $this->get(esewaSuccess($attempt));
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->count())->toBe(0)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Expired)->late_payment_at->toBeNull()->lookup_count->toBe($attempt->lookup_count + 1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
})->with(['CANCELED', 'PENDING']);

test('closed attempts are no longer swept for late payments once the late window has passed', function () {
    [, $attempt] = expiredEsewa();
    $this->travel(48)->hours();
    $lookups = $attempt->fresh()->lookup_count;

    $this->artisan('billing:reconcile-payments');

    expect($attempt->fresh())->status->toBe(AttemptStatus::Expired)->lookup_count->toBe($lookups);
});

/*
| #323 / #217: eSewa's own signed COMPLETE is evidence that money was taken. A later NOT_FOUND (the status check matches
| on the amount) goes to staff review with the amount and the evidence, and an attempt already in review is never expired.
*/

test('a signed COMPLETE return followed by NOT_FOUND goes to staff review with the evidence, not to abandoned', function () {
    [$invoice, $attempt] = startEsewa(113_050);
    Http::fake([ESEWA_STATUS => Http::response(esewaStatus($attempt, 'NOT_FOUND', refId: null))]);

    $this->get(esewaSuccess($attempt, '1,130.5'));

    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->lookups_closed_at->toBeNull()
        ->review_reason->toContain('Provider signed a paid return of 1,130.5 (code 000ABCD)')->toContain('1130.50 NPR')
        ->and($attempt->fresh()->raw['signed_return'])->toMatchArray(['status' => 'COMPLETE', 'total_amount' => '1,130.5', 'transaction_code' => '000ABCD'])
        ->and(Transaction::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('event', 'billing.payment.unmatched')->sole()->properties)->toMatchArray(['amount' => 113_050, 'currency' => 'NPR'])
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);

    // Nobody is asked to pay twice: the open review blocks a new attempt.
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->assertSessionHasErrors('billing');

    // Polling past the grace period does not expire it either, and does not repeat the to-do.
    $this->travel(15 + 121)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);
    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->raw->toHaveKey('signed_return')
        ->and(ActivityLog::query()->where('event', 'billing.payment.unmatched')->count())->toBe(1);
});

test('the signed evidence outlives an eSewa outage on the return and is used by the next poll', function () {
    [, $attempt] = startEsewa(113_050);
    Http::fake([ESEWA_STATUS => Http::sequence()->push(['code' => 0, 'error_message' => 'Service is currently unavailable'], 503)
        ->push(esewaStatus($attempt, 'NOT_FOUND', refId: null))]);

    $this->get(esewaSuccess($attempt));
    expect($attempt->fresh())->status->toBe(AttemptStatus::Pending)->raw->toHaveKey('signed_return');

    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);

    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toContain('Provider signed a paid return');
});

test('a forged or unsigned COMPLETE return does not stop a NOT_FOUND from ending the attempt', function (array $overrides, string $secret) {
    [, $attempt] = startEsewa(10_000);
    Http::fake([ESEWA_STATUS => Http::response(esewaFixture('status_not_found_cancelled', ['transaction_uuid' => $attempt->reference]))]);

    $this->get(esewaSuccess($attempt, '100', $overrides, $secret));
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);

    expect($attempt->fresh())->status->toBe(AttemptStatus::Expired)->raw->not->toHaveKey('signed_return');
})->with([
    'wrong secret' => [[], 'guessed-secret'],
    'tampered' => [['total_amount' => '1000'], ESEWA_SECRET],
]);

test('a signed COMPLETE return for an attempt closed as abandoned reopens it for review when eSewa still answers NOT_FOUND', function () {
    [, $attempt] = startEsewa(10_000);
    Http::fake([ESEWA_STATUS => Http::response(esewaFixture('status_not_found_cancelled', ['transaction_uuid' => $attempt->reference]))]);
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired);

    $this->travel(2)->minutes();
    $this->get(esewaSuccess($attempt, '100'));

    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toContain('Provider signed a paid return')
        ->and(Transaction::query()->count())->toBe(0);
});

test('an eSewa attempt already in review is not expired by NOT_FOUND past the grace period', function () {
    [, $attempt] = startEsewa();
    Http::fake([ESEWA_STATUS => Http::sequence()->push(esewaStatus($attempt, 'AMBIGUOUS', refId: null))
        ->whenEmpty(Http::response(esewaStatus($attempt, 'NOT_FOUND', refId: null)))]);

    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);

    $this->travel(61 + 121)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);

    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toBe('The provider could not confirm the payment yet');
});

test('an attempt in review for signed evidence still books if eSewa later finds it under our amount, once', function () {
    [$invoice, $attempt] = startEsewa(113_050);
    Http::fake([ESEWA_STATUS => Http::sequence()->push(esewaStatus($attempt, 'NOT_FOUND', refId: null))
        ->whenEmpty(Http::response(esewaStatus($attempt, 'COMPLETE')))]);

    $this->get(esewaSuccess($attempt));
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);

    expect($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->review_reason->toBeNull()
        ->and(Transaction::query()->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});
