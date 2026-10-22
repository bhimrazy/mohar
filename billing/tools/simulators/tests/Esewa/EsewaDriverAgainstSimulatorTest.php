<?php

use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use App\Support\Integrations\Endpoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\Esewa\EsewaApi;
use Mohar\Simulators\Providers\Esewa\EsewaSimulator;
use Tests\TestCase;

require_once dirname(__DIR__, 4).'/tests/Feature/PaymentsNepal/helpers.php';

uses(TestCase::class, RefreshDatabase::class);

/**
 * The eSewa driver, unchanged, against the esewa simulator (ADR 0016 §3-4): the signed form the customer's browser
 * posts, the payment page, the signed base64 return, and the status check that settles, over TLS to the scenario.
 * NPR amounts with paisa throughout. No Http::fake.
 */
beforeEach(function () {
    $this->scenario = Processes::start(EsewaSimulator::class);
    config([
        'integrations.outbound.allow_loopback' => true,
        'integrations.endpoints.overrides' => $this->scenario->simulator()->appConfiguration($this->scenario)['env']['INTEGRATION_ENDPOINTS'],
        'integrations.endpoints.ca_bundle' => $this->scenario->ca()->bundlePath(),
    ]);
    app()->forgetInstance(Endpoints::class);
    nepalGateway(EsewaDriver::KEY, ['sandbox' => '1', 'product_code' => EsewaSimulator::PRODUCT_CODE, 'secret_key' => EsewaSimulator::SECRET_KEY]);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

function esewaBrowser(): PendingRequest
{
    return Http::withOptions(['verify' => test()->scenario->ca()->bundlePath(), 'allow_redirects' => false]);
}

/**
 * The client starts paying a fresh invoice of 1,130.55 and the browser posts the signed form to eSewa. Returns the
 * invoice, the attempt, the form as the app served it, and eSewa's answer to the post.
 *
 * @param  array<string, string>  $tamper  form fields the browser changes before posting
 * @return array{0: Invoice, 1: PaymentAttempt, 2: array{action: string, fields: array<string, string>}, 3: Response}
 */
function esewaStart(array $tamper = []): array
{
    [, $invoice] = nprInvoice([113_055]);
    $checkout = test()->get((string) test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->headers->get('Location'));
    $form = $checkout->inertiaProps('form');
    $form['fields'] = (array) $form['fields'];

    expect($form['action'])->toBe(test()->scenario->apiUrl().'/api/epay/main/v2/form');

    return [$invoice, PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole(), $form, esewaBrowser()->asForm()->post($form['action'], [...$form['fields'], ...$tamper])];
}

/** The payer's choice on eSewa's page; returns where eSewa sends the browser. */
function esewaChoose(Response $booked, string $choice): string
{
    $answer = esewaBrowser()->post($booked->header('Location').'/'.$choice);
    expect($answer->status())->toBe(303);

    return (string) $answer->header('Location');
}

function esewaConfig(string $secret = EsewaSimulator::SECRET_KEY): GatewayConfig
{
    return new GatewayConfig(EsewaDriver::KEY, 'eSewa', ['sandbox' => '1', 'product_code' => EsewaSimulator::PRODUCT_CODE, 'secret_key' => $secret], sandbox: true);
}

test('a payment on eSewa settles from the status check, in paisa; answers keep the captured shapes', function () {
    [$invoice, $attempt, , $booked] = esewaStart();

    expect($booked->status())->toBe(302)
        ->and(esewaBrowser()->get($booked->header('Location'))->body())->toContain('Rs 1,130.55');
    $this->get(esewaChoose($booked, 'pay'))->assertRedirect(route('client.invoices.show', $invoice->id));

    $booking = $this->scenario->state()->get('bookings', EsewaSimulator::PRODUCT_CODE.':'.$attempt->reference);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->sole())->amount_in->toBe(113_055)->gateway_txn_id->toBe($booking['ref_id'])
        ->and($this->scenario->log()->entries('transaction_status')[0]['input']['total_amount'])->toBe('1130.55');

    // The UAT captures (tests/Fixtures/Esewa) as the expected shapes: the same keys, with the same JSON types.
    $shape = fn (array $body): array => array_map(get_debug_type(...), $body);
    $answer = esewaBrowser()->get($this->scenario->apiUrl().'/api/epay/transaction/status/', ['product_code' => 'EPAYTEST', 'total_amount' => '1130.55', 'transaction_uuid' => $attempt->reference])->json();
    $fixture = fn (string $name): array => json_decode((string) file_get_contents(base_path("tests/Fixtures/Esewa/{$name}.json")), true);
    expect($shape($answer))->toBe($shape($fixture('status_complete')))
        ->and(esewaBrowser()->get($this->scenario->apiUrl().'/api/epay/transaction/status/', ['product_code' => 'EPAYTEST', 'total_amount' => '100', 'transaction_uuid' => 'unknown-uuid'])->json())
        ->toBe([...$fixture('status_not_found_cancelled'), 'transaction_uuid' => 'unknown-uuid']);
});

test('a duplicate return and a second Pay book the payment once, with no credit', function () {
    [$invoice, $attempt, , $booked] = esewaStart();
    $return = esewaChoose($booked, 'pay');

    $this->get($return);
    $this->get($return);
    $this->get(esewaChoose($booked, 'pay'));

    expect(Transaction::query()->count())->toBe(1)
        ->and(CreditLedgerEntry::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded);
});

test('a cancel on eSewa returns to the failure URL and ends the attempt without paying', function () {
    [$invoice, $attempt, , $booked] = esewaStart();

    $this->get(esewaChoose($booked, 'cancel'))->assertSessionHas('inertia.flash_data.toast.message', 'Payment cancelled. You can try again or choose another way to pay.');

    expect($attempt->fresh()->status)->not->toBe(AttemptStatus::Pending)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0);
});

test('a session left PENDING is booked by the poller once eSewa completes it', function () {
    [$invoice, $attempt, , $booked] = esewaStart();

    $this->get(esewaChoose($booked, 'leave'));
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);

    $this->scenario->simulator()->actions()['settle'](['transaction_uuid' => $attempt->reference, 'status' => 'COMPLETE'], $this->scenario);
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)->and(Transaction::query()->count())->toBe(1);
});

test('a session left PENDING that expires at eSewa closes the attempt without paying', function () {
    [$invoice, $attempt, , $booked] = esewaStart();

    $this->get(esewaChoose($booked, 'leave'));
    $this->scenario->simulator()->actions()['settle'](['transaction_uuid' => $attempt->reference, 'status' => 'EXPIRED'], $this->scenario);
    $this->travel(7)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0);
});

test('eSewa reporting another amount is refused and goes to staff review', function () {
    [$invoice, $attempt] = esewaStart();

    $this->get($this->scenario->simulator()->actions()['pay'](['transaction_uuid' => $attempt->reference, 'settled_amount' => '1,000.00'], $this->scenario)['redirect']);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toContain('Amount mismatch');
});

test('a forged return is not trusted: the status check of our own amount decides', function (string $variant) {
    [$invoice, $attempt] = esewaStart();

    // Forged before any payment: claims COMPLETE, but eSewa still has the booking PENDING.
    $forged = ['transaction_code' => '000FAKE', 'status' => 'COMPLETE', 'total_amount' => '1,130.55', 'transaction_uuid' => $attempt->reference, 'product_code' => 'EPAYTEST', 'signed_field_names' => EsewaApi::RETURN_SIGNED_FIELDS];
    $forged['signature'] = EsewaApi::signature($forged, EsewaApi::RETURN_SIGNED_FIELDS, 'guessed-secret');
    $this->get(route('billing.gateways.return', [EsewaDriver::KEY, 'data' => base64_encode((string) json_encode($forged))]));
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
    // The return route looks up at most once a minute per attempt.
    $this->travel(2)->minutes();

    // Paid for real, with a return that is badly signed, tampered (ten times the amount) or unsigned: booked once, for
    // the amount we asked.
    $this->get($this->scenario->simulator()->actions()['pay'](['transaction_uuid' => $attempt->reference, 'variant' => $variant], $this->scenario)['redirect']);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->sole()->amount_in)->toBe(113_055)
        ->and(array_column(array_column($this->scenario->log()->entries('transaction_status'), 'input'), 'total_amount'))->each->toBe('1130.55');
})->with(['wrong_secret', 'tampered', 'unsigned']);

test('a form changed in the browser is refused by eSewa (ES104), and a reused uuid is refused', function () {
    [, , $form, $booked] = esewaStart(['total_amount' => '1.00', 'amount' => '1.00']);

    expect($booked->status())->toBe(400)->and($booked->json('code'))->toBe('ES104')
        ->and($this->scenario->state()->all('bookings'))->toBe([]);

    expect(esewaBrowser()->asForm()->post($form['action'], $form['fields'])->status())->toBe(302)
        ->and(esewaBrowser()->asForm()->post($form['action'], $form['fields'])->json())->toMatchArray(['code' => 0]);
});

test('a status check that times out while the customer waits is left to the poller, which settles it', function () {
    config(['payments_nepal.http.interactive_timeout_seconds' => 2]);
    [$invoice, $attempt, , $booked] = esewaStart();
    $return = esewaChoose($booked, 'pay');
    $this->scenario->faults()->add('transaction_status', Fault::commitThenHang(4), nth: 1);

    $this->get($return);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)->and($attempt->fresh()->status)->toBe(AttemptStatus::Pending);

    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)->and(Transaction::query()->count())->toBe(1);
});

test('refunds made in the eSewa portal look up as full or partial refunds', function () {
    [, $attempt, , $booked] = esewaStart();
    $this->get(esewaChoose($booked, 'pay'));
    $settle = fn (string $status) => $this->scenario->simulator()->actions()['settle'](['transaction_uuid' => $attempt->reference, 'status' => $status], $this->scenario);
    $driver = app(EsewaDriver::class);

    $settle('PARTIAL_REFUND');
    expect($driver->lookup(esewaConfig(), $attempt->fresh()->toRef())->status)->toBe(LookupResult::PARTIALLY_REFUNDED);
    $settle('FULL_REFUND');
    expect($driver->lookup(esewaConfig(), $attempt->fresh()->toRef())->status)->toBe(LookupResult::REFUNDED);
});

test('Test connection accepts the merchant secret and names a wrong one', function () {
    $driver = app(EsewaDriver::class);

    expect($driver->testConnection(esewaConfig())->ok)->toBeTrue()
        ->and($driver->testConnection(esewaConfig('wrong-secret'))->message)->toContain('rejected the product code or secret key');
});
