<?php

use App\Data\Billing\GatewayConfig;
use App\Data\Integrations\CertificateInfo;
use App\Modules\Billing\Services\GatewayManager;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsNepal\Drivers\ConnectipsDriver;
use App\Support\Integrations\Endpoints;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\Connectips\ConnectipsSimulator as Sim;
use Tests\TestCase;

require_once dirname(__DIR__, 4).'/tests/Feature/PaymentsNepal/helpers.php';

uses(TestCase::class, RefreshDatabase::class);

/**
 * The connectIPS driver, unchanged, against the connectips simulator (ADR 0016 §3-4, spec §13 criterion 20): the
 * scenario's CREDITOR.pfx uploaded through the gateway form, the signed form the browser posts, the hosted page, the
 * return with only TXNID, and validatetxn plus gettxndetail settling over TLS. No Http::fake.
 */
beforeEach(function () {
    $this->scenario = Processes::start(Sim::class);
    $this->act = fn (string $name, array $input = []): array => $this->scenario->simulator()->actions()[$name]($input, $this->scenario);
    config([
        'integrations.outbound.allow_loopback' => true,
        'integrations.endpoints.overrides' => $this->scenario->simulator()->appConfiguration($this->scenario)['env']['INTEGRATION_ENDPOINTS'],
        'integrations.endpoints.ca_bundle' => $this->scenario->ca()->bundlePath(),
    ]);
    app()->forgetInstance(Endpoints::class);

    // The return URL shown on the gateway form is what the merchant registers with NCHL, for success and failure.
    $return = route('billing.gateways.return', ConnectipsDriver::KEY);
    ($this->act)('register_return_urls', ['success' => $return, 'failure' => $return]);
    // The app's Nepal date, so a run across midnight cannot split the form's date from the simulator's check.
    ($this->act)('configure', ['frozen_date' => CarbonImmutable::now('Asia/Kathmandu')->format('d-m-Y')]);

    actingAsStaff();
    confirmPassword();
    $this->put(route('admin.billing.gateways.update', ConnectipsDriver::KEY), [
        'display_name' => 'connectIPS', 'is_active' => true, 'show_on_order_form' => true,
        'settings' => ['sandbox' => '1', 'merchant_id' => Sim::MERCHANT_ID, 'app_id' => Sim::APP_ID, 'app_name' => Sim::APP_NAME, 'app_password' => Sim::APP_PASSWORD],
        'certificates' => ['signing_key' => [
            'file' => UploadedFile::fake()->createWithContent('CREDITOR.pfx', (string) file_get_contents(Sim::pfxPath($this->scenario))),
            'passphrase' => Sim::PFX_PASSPHRASE,
        ]],
    ])->assertSessionHasNoErrors();
});

afterEach(function () {
    Processes::stop($this->scenario);
});

function cipsSimBrowser(): PendingRequest
{
    return Http::withOptions(['verify' => test()->scenario->ca()->bundlePath(), 'allow_redirects' => false])->timeout(5);
}

/**
 * The client pays a fresh invoice of 1,130.55 and the browser posts the signed form to connectIPS. Returns the
 * invoice, the attempt, the form as the app served it, and NCHL's answer to the post.
 *
 * @param  array<string, string>  $tamper  form fields the browser changes before posting
 * @return array{0: Invoice, 1: PaymentAttempt, 2: array{action: string, fields: array<string, string>}, 3: Response}
 */
function cipsSimStart(array $tamper = []): array
{
    [, $invoice] = nprInvoice([113_055]);
    $checkout = test()->get((string) test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => ConnectipsDriver::KEY])->headers->get('Location'));
    $form = $checkout->inertiaProps('form');
    $form['fields'] = (array) $form['fields'];

    expect($form['action'])->toBe(test()->scenario->apiUrl().'/connectipswebgw/loginpage');

    return [$invoice, PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole(), $form, cipsSimBrowser()->asForm()->post($form['action'], [...$form['fields'], ...$tamper])];
}

/** The payer's choice through the control plane; returns where NCHL sends the browser. */
function cipsSimChoose(PaymentAttempt $attempt, string $choice, array $options = []): string
{
    return (test()->act)($choice, ['txn_id' => $attempt->provider_ref, ...$options])['redirect'];
}

test('the uploaded .pfx signs a form NCHL accepts; Pay on the hosted page settles from validatetxn with the fee and bank', function () {
    // Rs 1,130.55 is in NCHL's NPR 4 slab (above 500, up to 5,000), borne by the merchant.
    $stored = Gateway::query()->where('key', ConnectipsDriver::KEY)->sole();
    expect(app(GatewayManager::class)->config($stored)->certificate('signing_key'))->toBeInstanceOf(CertificateInfo::class)
        ->subject->toContain('Simulated connectIPS creditor');

    [$invoice, $attempt, $form, $page] = cipsSimStart();
    expect($page->status())->toBe(200)->and($page->body())->toContain('NPR 1,130.55')
        ->and($form['fields'])->toMatchArray(['TXNAMT' => '113055', 'TXNCRNCY' => 'NPR', 'TXNID' => $attempt->provider_ref]);

    // The browser clicks Pay on the hosted page and follows NCHL back to the app with only TXNID.
    preg_match('#action="(/connectipswebgw/choice/[0-9a-f]+/pay)"#', $page->body(), $pay);
    $back = cipsSimBrowser()->post($this->scenario->apiUrl().$pay[1]);
    expect($back->status())->toBe(303)->and($back->header('Location'))->toBe(route('billing.gateways.return', [ConnectipsDriver::KEY, 'TXNID' => $attempt->provider_ref]));
    $this->get($back->header('Location'))->assertRedirect(route('client.invoices.show', $invoice->id));

    $txn = $this->scenario->state()->get('txns', Sim::APP_ID.':'.$attempt->provider_ref);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->sole())->amount_in->toBe(113_055)->fee->toBe(400)->gateway_txn_id->toBe($txn['nchl_txn_id'])
        ->and($attempt->fresh()->raw)->toMatchArray(['debit_bank_code' => '0110', 'credit_status' => '000'])
        ->and(array_column(array_column($this->scenario->log()->entries('validatetxn'), 'input'), 'txnAmt'))->each->toBe('113055');
});

test('a duplicate return and a second Pay book the payment once, with no credit', function () {
    [$invoice, $attempt] = cipsSimStart();
    $return = cipsSimChoose($attempt, 'pay');

    $this->get($return);
    $this->get($return);
    $this->get(cipsSimChoose($attempt, 'pay'));

    expect(Transaction::query()->count())->toBe(1)
        ->and(CreditLedgerEntry::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('hosted page outcomes: decline fails, cancel and leave stay open until they expire', function (string $choice, AttemptStatus $now, int $minutes) {
    [$invoice, $attempt] = cipsSimStart();

    $this->get(cipsSimChoose($attempt, $choice));
    expect($attempt->fresh()->status)->toBe($now);

    $this->travel($minutes)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();
    expect($attempt->fresh()->status)->toBe($now === AttemptStatus::Failed ? AttemptStatus::Failed : AttemptStatus::Expired)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0);
})->with([
    'decline (FAILED)' => ['decline', AttemptStatus::Failed, 6],
    'cancel (NOT FOUND, then TTL plus grace)' => ['cancel', AttemptStatus::Pending, 30 + 121],
    'leave before the OTP (INCOMPLETE, then TTL)' => ['leave', AttemptStatus::Pending, 31],
]);

test('a payment NCHL completes after the customer left is booked by the poller', function () {
    [$invoice, $attempt] = cipsSimStart();
    $this->get(cipsSimChoose($attempt, 'leave'));

    ($this->act)('settle', ['txn_id' => $attempt->provider_ref, 'outcome' => 'success']);
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)->and(Transaction::query()->count())->toBe(1);
});

test('the credit leg decides: DEFER is paid, a failed credit goes to staff review, a customer-borne fee is not ours', function (string $choice, array $options, AttemptStatus $expected, ?int $fee) {
    [, $attempt] = cipsSimStart();

    $this->get(cipsSimChoose($attempt, $choice, $options));

    expect($attempt->fresh()->status)->toBe($expected)
        ->and(Transaction::query()->first()?->fee)->toBe($fee);
})->with([
    'deferred credit' => ['defer', [], AttemptStatus::Succeeded, 400],
    'credit failed (F01)' => ['pay', ['credit_status' => 'F01'], AttemptStatus::Ambiguous, null],
    'customer pays the fee' => ['pay', ['charge_liability' => 'CG'], AttemptStatus::Succeeded, 0],
]);

test('an answer about another amount, transaction, merchant or app goes to staff review', function (string $variant) {
    [$invoice, $attempt] = cipsSimStart();
    ($this->act)('configure', ['echo_variant' => $variant]);

    $this->get(cipsSimChoose($attempt, 'pay'));

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0);
})->with(['wrong_amount', 'wrong_reference', 'wrong_merchant', 'wrong_app']);

test('validatetxn amounts in rupees are read as the same payment', function () {
    [$invoice, $attempt] = cipsSimStart();
    ($this->act)('configure', ['validate_amount_style' => 'rupees']);

    $this->get(cipsSimChoose($attempt, 'pay'));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('a form changed in the browser is refused by NCHL, and a reused TXNID is refused', function () {
    [, $attempt, $form, $page] = cipsSimStart(['TXNAMT' => '100']);

    expect($page->status())->toBe(400)->and($page->body())->toContain('Invalid token')
        ->and($this->scenario->state()->all('txns'))->toBe([]);
    expect(cipsSimBrowser()->asForm()->post($form['action'], $form['fields'])->status())->toBe(200)
        ->and(cipsSimBrowser()->asForm()->post($form['action'], $form['fields'])->status())->toBe(403);
});

// The token's separator is the open point Q3: if NCHL turns out to sign ", ", every form is refused, and the one-line
// change is ConnectipsDriver::TOKEN_SEPARATOR.
test('a scenario that expects ", " between the token pairs refuses our form', function () {
    ($this->act)('configure', ['token_spacing' => 'space']);

    [, , , $page] = cipsSimStart();

    expect($page->status())->toBe(400)->and($page->body())->toContain('Invalid token');
});

test('a validatetxn that hangs while the customer waits is left to the poller, which settles it', function () {
    config(['payments_nepal.http.interactive_timeout_seconds' => 2]);
    [$invoice, $attempt] = cipsSimStart();
    $return = cipsSimChoose($attempt, 'pay');
    $this->scenario->faults()->add('validatetxn', Fault::commitThenHang(4), nth: 1);

    $this->get($return);
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);

    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)->and(Transaction::query()->count())->toBe(1);
});

test('a failing gettxndetail settles nothing; the poller books the payment once the details come back', function () {
    [$invoice, $attempt] = cipsSimStart();
    $this->scenario->faults()->add('gettxndetail', Fault::status(500), nth: 1);

    $this->get(cipsSimChoose($attempt, 'pay'));
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending)->and(Transaction::query()->count())->toBe(0);

    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->sole())->fee->toBe(400)->gateway_txn_id->not->toBe($attempt->provider_ref);
});

test('a wrong app password puts the payment in staff review, and Test connection names it', function () {
    [, $attempt] = cipsSimStart();
    $gateway = Gateway::query()->where('key', ConnectipsDriver::KEY)->sole();
    $gateway->update(['config' => [...$gateway->config, 'app_password' => 'wrong-password']]);

    $this->get(cipsSimChoose($attempt, 'pay'));
    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toContain('HTTP 401');

    $driver = app(ConnectipsDriver::class);
    $config = fn (): GatewayConfig => app(GatewayManager::class)->config(Gateway::query()->where('key', ConnectipsDriver::KEY)->sole());
    expect($driver->testConnection($config())->message)->toContain('rejected the app ID or app password');

    $gateway->update(['config' => [...$gateway->fresh()->config, 'app_password' => Sim::APP_PASSWORD]]);
    expect($driver->testConnection($config()))->ok->toBeTrue()
        ->and($this->scenario->state()->all('txns'))->toHaveCount(1);
});
