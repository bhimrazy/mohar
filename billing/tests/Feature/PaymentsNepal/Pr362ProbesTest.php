<?php

/*
| Review probes of PR #362 (round 1), adopted as tests.
*/

use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

const RB_SECRET = 'fake-esewa-secret';
const RB_STATUS = 'https://rc.esewa.com.np/api/epay/transaction/status/*';

beforeEach(function () {
    Http::preventStrayRequests();
    nepalGateway(EsewaDriver::KEY, ['sandbox' => '1', 'product_code' => 'EPAYTEST', 'secret_key' => RB_SECRET]);
});

function rbStart(int $amount = 113_050): array
{
    [, $invoice] = nprInvoice([$amount]);
    $checkout = test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->assertRedirect()->headers->get('Location');
    test()->get((string) $checkout);

    return [$invoice, PaymentAttempt::query()->where('invoice_id', $invoice->id)->latest('id')->firstOrFail()];
}

function rbSuccess(PaymentAttempt $attempt, string $total = '1,130.5', array $signed = [], array $extraQuery = []): string
{
    $payload = [...['transaction_code' => '000ABCD', 'status' => 'COMPLETE', 'total_amount' => $total, 'transaction_uuid' => $attempt->reference,
        'product_code' => 'EPAYTEST', 'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names'], ...$signed];
    $message = collect(explode(',', $payload['signed_field_names']))->map(fn (string $f) => $f.'='.$payload[$f])->implode(',');
    $payload['signature'] = base64_encode(hash_hmac('sha256', $message, RB_SECRET, true));

    return route('billing.gateways.return', [EsewaDriver::KEY, 'data' => base64_encode((string) json_encode($payload)), ...$extraQuery]);
}

function rbStatus(PaymentAttempt $attempt, string $status, float|int $total = 1130.5, ?string $refId = '0007G36'): array
{
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/Esewa/status_complete.json'), true);

    return [...$fixture, 'transaction_uuid' => $attempt->reference, 'total_amount' => $total, 'status' => $status, 'ref_id' => $refId];
}

test('P1 review stays blocking after the attempt expiry passes', function () {
    [$invoice, $attempt] = rbStart();
    Http::fake([RB_STATUS => Http::response(rbStatus($attempt, 'NOT_FOUND', refId: null))]);
    $this->get(rbSuccess($attempt));
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);

    $this->travel(65)->minutes();
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->assertSessionHasErrors('billing');
});

test('P2 signed return is kept when the poller looked the attempt up in the last minute', function () {
    [, $attempt] = rbStart();
    Http::fake([RB_STATUS => Http::sequence()->push(rbStatus($attempt, 'PENDING', refId: null))
        ->whenEmpty(Http::response(rbStatus($attempt, 'NOT_FOUND', refId: null)))]);
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);
    $this->travel(30)->seconds();
    $this->get(rbSuccess($attempt));
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);

    expect($attempt->fresh())->raw->toHaveKey('signed_return')->status->toBe(AttemptStatus::Ambiguous);
});

test('P3 a valid signed return for A with ?ref=B does not touch B', function () {
    [$client, $invoiceA] = nprInvoice([113_050]);
    $invoiceB = issueInvoice($client, [113_050]);
    foreach ([$invoiceA, $invoiceB] as $inv) {
        $this->get((string) $this->post(route('client.invoices.pay', $inv->id), ['gateway' => EsewaDriver::KEY])->headers->get('Location'));
    }
    $a = PaymentAttempt::query()->where('invoice_id', $invoiceA->id)->sole();
    $b = PaymentAttempt::query()->where('invoice_id', $invoiceB->id)->sole();
    Http::fake([RB_STATUS => Http::response(rbStatus($a, 'NOT_FOUND', refId: null))]);
    $this->get(rbSuccess($a, extraQuery: ['ref' => $b->reference]));

    expect($b->fresh())->status->toBe(AttemptStatus::Pending)->raw->not->toHaveKey('signed_return')
        ->and($a->fresh()->status)->toBe(AttemptStatus::Ambiguous);
});

test('P4 a signed return under another product code is not evidence', function () {
    [, $attempt] = rbStart();
    Http::fake([RB_STATUS => Http::response(rbStatus($attempt, 'NOT_FOUND', refId: null))]);
    $this->get(rbSuccess($attempt, signed: ['product_code' => 'OTHERMERCH']));
    expect($attempt->fresh()->raw ?? [])->not->toHaveKey('signed_return');
});

test('P5 unsigned (no signature field list) COMPLETE is not evidence', function () {
    [, $attempt] = rbStart();
    Http::fake([RB_STATUS => Http::response(rbStatus($attempt, 'NOT_FOUND', refId: null))]);
    $payload = ['transaction_code' => 'X', 'status' => 'COMPLETE', 'total_amount' => '1,130.5', 'transaction_uuid' => $attempt->reference, 'product_code' => 'EPAYTEST'];
    $this->get(route('billing.gateways.return', [EsewaDriver::KEY, 'data' => base64_encode((string) json_encode($payload))]));
    expect($attempt->fresh()->raw ?? [])->not->toHaveKey('signed_return');
});

test('P6 reopened attempt on an invoice paid another way: later COMPLETE goes to credit and is flagged, not silent', function () {
    [$invoice, $attempt] = rbStart();
    Http::fake([RB_STATUS => Http::sequence()->push(rbStatus($attempt, 'NOT_FOUND', refId: null))
        ->push(rbStatus($attempt, 'NOT_FOUND', refId: null))
        ->whenEmpty(Http::response(rbStatus($attempt, 'COMPLETE')))]);
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired);

    app(ApplyPayment::class)->handle($invoice->fresh(), new PaymentData(amount: 113_050, gateway: 'banktransfer', gatewayTxnId: 'BT-1'));
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    $this->travel(2)->minutes();
    $this->get(rbSuccess($attempt));
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);

    $this->travel(10)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);
    $fresh = $attempt->fresh();
    expect($fresh)->late_payment_at->not->toBeNull()
        ->and(ActivityLog::query()->where('event', 'billing.payment.late')->count())->toBe(1)
        ->and(Transaction::query()->where('gateway', EsewaDriver::KEY)->count())->toBe(1);
    expect($fresh->check_reason)->not->toBeNull();
});

test('P7 activity log dedupe across AMBIGUOUS flip', function () {
    [, $attempt] = rbStart();
    Http::fake([RB_STATUS => Http::sequence()->push(rbStatus($attempt, 'NOT_FOUND', refId: null))
        ->push(rbStatus($attempt, 'AMBIGUOUS', refId: null))
        ->whenEmpty(Http::response(rbStatus($attempt, 'NOT_FOUND', refId: null)))]);
    $this->get(rbSuccess($attempt));
    foreach (range(1, 4) as $i) {
        $this->travel(6)->minutes();
        $this->artisan('billing:reconcile-payments', ['--only' => 'open']);
    }
    expect(ActivityLog::query()->where('event', 'billing.payment.unmatched')->count())->toBe(1);
});

test('a reopened attempt keeps blocking a new payment after its old expiry', function () {
    [$invoice, $attempt] = rbStart();
    Http::fake([RB_STATUS => Http::response(rbStatus($attempt, 'NOT_FOUND', refId: null))]);
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired);

    $this->travel(60)->minutes();
    $this->get(rbSuccess($attempt));
    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->expires_at->toBeNull();

    $this->travel(61)->minutes();
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])
        ->assertSessionHasErrors(['billing' => 'A payment with Esewa is already in progress for this invoice. Check its status before paying again.']);
});

test('a driver cannot plant signed evidence through its own lookup answer', function () {
    [, $attempt] = rbStart();
    $body = [...rbStatus($attempt, 'NOT_FOUND', refId: null), 'signed_return' => ['status' => 'COMPLETE']];
    Http::fake([RB_STATUS => Http::response($body)]);

    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);

    expect($attempt->fresh())->status->toBe(AttemptStatus::Expired)->raw->not->toHaveKey('signed_return');
});

/** A signed COMPLETE that eSewa cannot find: the attempt waits in staff review. */
function rbInReview(): array
{
    [$invoice, $attempt] = rbStart();
    Http::fake([RB_STATUS => Http::response(rbStatus($attempt, 'NOT_FOUND', refId: null))]);
    test()->get(rbSuccess($attempt));
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);

    return [$invoice, $attempt];
}

test('staff mark an attempt in review as paid: booked once with the eSewa reference, double submit refused', function () {
    [$invoice, $attempt] = rbInReview();
    billingStaff();
    confirmPassword();
    $url = route('admin.billing.payment-attempts.resolve', $attempt);

    $this->post($url, ['outcome' => 'paid', 'amount' => '1130.50'])->assertSessionHasErrors('provider_reference');
    $this->post($url, ['outcome' => 'paid', 'provider_reference' => '000ABCD'])->assertSessionHasErrors('amount');
    $this->post($url, ['outcome' => 'paid', 'amount' => '1,130.50', 'provider_reference' => ' 000ABCD '])->assertSessionHasNoErrors();
    $this->post($url, ['outcome' => 'paid', 'amount' => '1,130.50', 'provider_reference' => '000ABCD'])->assertSessionHasErrors('billing');

    expect($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->provider_txn_id->toBe('000ABCD')
        ->and(Transaction::query()->where('gateway', EsewaDriver::KEY)->sole())->amount_in->toBe(113_050)->gateway_txn_id->toBe('000ABCD')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(CreditLedgerEntry::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('event', 'billing.payment.resolved')->sole()->properties)->toMatchArray(['outcome' => 'paid', 'provider_reference' => '000ABCD']);
});

test('marking as paid with a reference another attempt already used books nothing', function () {
    [$client, $invoiceA] = nprInvoice([113_050]);
    $invoiceB = issueInvoice($client, [113_050]);
    foreach ([$invoiceA, $invoiceB] as $invoice) {
        $this->get((string) $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->headers->get('Location'));
    }
    $first = PaymentAttempt::query()->where('invoice_id', $invoiceA->id)->sole();
    $second = PaymentAttempt::query()->where('invoice_id', $invoiceB->id)->sole();
    Http::fake([RB_STATUS => fn ($request) => Http::response(rbStatus(str_contains((string) $request->url(), $first->reference) ? $first : $second, 'NOT_FOUND', refId: null))]);
    $this->get(rbSuccess($first));
    $this->get(rbSuccess($second));

    billingStaff();
    confirmPassword();
    $this->post(route('admin.billing.payment-attempts.resolve', $first), ['outcome' => 'paid', 'amount' => '1,130.50', 'provider_reference' => 'DUP-1'])->assertSessionHasNoErrors();
    $this->post(route('admin.billing.payment-attempts.resolve', $second), ['outcome' => 'paid', 'amount' => '1,130.50', 'provider_reference' => 'DUP-1'])->assertSessionHasErrors('billing');

    expect($second->fresh()->status)->toBe(AttemptStatus::Ambiguous)
        ->and(Transaction::query()->count())->toBe(1)
        ->and($invoiceB->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

test('staff close an attempt in review as not paid: the invoice can be paid again and a replayed return cannot reopen it', function () {
    [$invoice, $attempt] = rbInReview();
    $signedReturn = rbSuccess($attempt);
    billingStaff();
    confirmPassword();
    $url = route('admin.billing.payment-attempts.resolve', $attempt);

    $this->post($url, ['outcome' => 'not_paid'])->assertSessionHasNoErrors();
    $this->post($url, ['outcome' => 'not_paid'])->assertSessionHasErrors('billing');
    $this->post($url, ['outcome' => 'paid', 'amount' => '1,130.50', 'provider_reference' => 'LATE-1'])->assertSessionHasErrors('billing');

    expect($attempt->fresh())->status->toBe(AttemptStatus::Failed)->lookups_closed_at->not->toBeNull()
        ->and(ActivityLog::query()->where('event', 'billing.payment.resolved')->count())->toBe(1);

    // The customer's old signed return comes back: nothing reopens, and nothing is looked up.
    $this->travel(2)->minutes();
    $this->get($signedReturn);
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Failed);

    $this->actingAs(Client::query()->findOrFail($invoice->client_id)->users()->firstOrFail());
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->assertSessionHasNoErrors();
    expect(PaymentAttempt::query()->where('invoice_id', $invoice->id)->count())->toBe(2);
});

test('resolving an attempt in review needs the payments permission', function () {
    [, $attempt] = rbInReview();
    actingAsStaff(['billing.invoices.view']);
    confirmPassword();

    $this->post(route('admin.billing.payment-attempts.resolve', $attempt), ['outcome' => 'paid', 'amount' => '1,130.50', 'provider_reference' => 'X-1'])->assertForbidden();
    $this->post(route('admin.billing.payment-attempts.resolve', $attempt), ['outcome' => 'not_paid'])->assertForbidden();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous)->and(Transaction::query()->count())->toBe(0);
});

test('a signed return inside the lookup floor is kept, so the next NOT_FOUND is reviewed, not abandoned', function () {
    [, $attempt] = rbStart();
    Http::fake([RB_STATUS => Http::sequence()->push(rbStatus($attempt, 'PENDING', refId: null))
        ->whenEmpty(Http::response(rbStatus($attempt, 'NOT_FOUND', refId: null)))]);
    $this->artisan('billing:reconcile-payments', ['--only' => 'open']);
    $this->travel(30)->seconds();
    $this->get(rbSuccess($attempt));

    expect($attempt->fresh())->status->toBe(AttemptStatus::Pending)->lookup_count->toBe(1)->raw->toHaveKey('signed_return');
    Http::assertSentCount(1);
});

test('Q1 mark as paid refuses a reference already on another invoice, naming it, and books nothing', function () {
    [$invoice, $attempt] = rbInReview();
    $other = issueInvoice(Client::query()->findOrFail($invoice->client_id), [50_000]);
    app(ApplyPayment::class)->handle($other, new PaymentData(amount: 50_000, gateway: EsewaDriver::KEY, gatewayTxnId: 'TAKEN-1'));
    billingStaff();
    confirmPassword();

    $this->post(route('admin.billing.payment-attempts.resolve', $attempt), ['outcome' => 'paid', 'amount' => '1130.50', 'provider_reference' => 'TAKEN-1'])
        ->assertSessionHasErrors(['billing' => 'That reference is already recorded on invoice '.$other->displayNumber().'. Check the reference in the portal.']);

    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->transaction_id->toBeNull()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(1)
        ->and(ActivityLog::query()->where('event', 'billing.payment.resolved')->count())->toBe(0);
});

test('a lookup that finds a transaction already booked on another invoice does not close the attempt as paid', function () {
    [$invoice, $attempt] = rbStart();
    $other = issueInvoice(Client::query()->findOrFail($invoice->client_id), [50_000]);
    app(ApplyPayment::class)->handle($other, new PaymentData(amount: 50_000, gateway: EsewaDriver::KEY, gatewayTxnId: 'TAKEN-2'));
    Http::fake([RB_STATUS => Http::response(rbStatus($attempt, 'COMPLETE', refId: 'TAKEN-2'))]);

    $this->get(rbSuccess($attempt));

    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->transaction_id->toBeNull()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

test('Q2 resolving without a recent password changes nothing', function () {
    [, $attempt] = rbInReview();
    billingStaff();

    $this->post(route('admin.billing.payment-attempts.resolve', $attempt), ['outcome' => 'not_paid'])->assertRedirect();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);
});

test('Q3 mark as paid refuses an amount other than ours and points to Add payment', function (string $seen) {
    [$invoice, $attempt] = rbStart();
    Http::fake([RB_STATUS => Http::response(rbStatus($attempt, 'NOT_FOUND', refId: null))]);
    $this->get(rbSuccess($attempt, '1,000.0'));
    billingStaff();
    confirmPassword();

    $this->post(route('admin.billing.payment-attempts.resolve', $attempt), ['outcome' => 'paid', 'amount' => $seen, 'provider_reference' => '000ABCD'])
        ->assertSessionHasErrors('billing');

    expect(session('errors')->first('billing'))->toContain('Add payment')
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous)
        ->and(Transaction::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
})->with(['less' => '1000.00', 'more' => '1130.51']);

test('Q4 resolving a pending attempt is refused', function () {
    [, $attempt] = rbStart();
    billingStaff();
    confirmPassword();

    $this->post(route('admin.billing.payment-attempts.resolve', $attempt), ['outcome' => 'not_paid'])->assertSessionHasErrors('billing');

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
});

test('Q6 after Not paid, neither Check now nor a lookup in flight reopens the attempt, but a found payment still books late', function () {
    [$invoice, $attempt] = rbInReview();
    billingStaff();
    confirmPassword();
    $this->post(route('admin.billing.payment-attempts.resolve', $attempt), ['outcome' => 'not_paid'])->assertSessionHasNoErrors();

    $this->travel(2)->minutes();
    $this->post(route('admin.billing.payment-attempts.check', $attempt));
    $this->get(rbSuccess($attempt));
    $this->artisan('billing:reconcile-payments');

    expect($attempt->fresh())->status->toBe(AttemptStatus::Failed)->lookups_closed_at->not->toBeNull()
        ->and(ActivityLog::query()->where('event', 'billing.payment.unmatched')->count())->toBe(1);

    $this->actingAs(Client::query()->findOrFail($invoice->client_id)->users()->firstOrFail());
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->assertSessionHasNoErrors();
});

test('a payment eSewa finds after Not paid is still booked as a late payment', function () {
    [$invoice, $attempt] = rbStart();
    Http::fake([RB_STATUS => Http::sequence()->push(rbStatus($attempt, 'NOT_FOUND', refId: null))->whenEmpty(Http::response(rbStatus($attempt, 'COMPLETE')))]);
    $this->get(rbSuccess($attempt));
    billingStaff();
    confirmPassword();
    $this->post(route('admin.billing.payment-attempts.resolve', $attempt), ['outcome' => 'not_paid'])->assertSessionHasNoErrors();

    $this->post(route('admin.billing.payment-attempts.check', $attempt));

    expect($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->late_payment_at->not->toBeNull()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('Q5 the review blocks for 14 days: after that the attempt stays on the review list but a new payment is allowed', function () {
    [$invoice, $attempt] = rbInReview();
    $this->travel(13)->days();
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->assertSessionHasErrors('billing');

    $this->travel(2)->days();
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->assertSessionHasNoErrors();
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);
});

test('Mark as paid refuses an amount longer than 12 digits before the decimal point, books nothing', function (string $amount) {
    [$invoice, $attempt] = rbInReview();
    billingStaff();
    confirmPassword();

    $this->post(route('admin.billing.payment-attempts.resolve', $attempt), ['outcome' => 'paid', 'amount' => $amount, 'provider_reference' => '000ABCD'])
        ->assertSessionHasErrors('amount');

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);
})->with(['184467440737099612.16', '92233720368547758.07', '1,000,000,000,000.00']);
