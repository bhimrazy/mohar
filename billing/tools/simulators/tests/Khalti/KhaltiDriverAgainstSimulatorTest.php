<?php

use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use App\Support\Integrations\Endpoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\Khalti\KhaltiSimulator;
use Tests\TestCase;

require_once dirname(__DIR__, 4).'/tests/Feature/PaymentsNepal/helpers.php';

uses(TestCase::class, RefreshDatabase::class);

/**
 * The Khalti driver, unchanged, against the khalti simulator (ADR 0016 §3-4): initiate, the hosted page, the unsigned
 * return followed by the browser, and the lookup that settles, all over TLS to the scenario, pinned by the outbound
 * guard. NPR amounts with paisa throughout. No Http::fake.
 */
beforeEach(function () {
    $this->scenario = Processes::start(KhaltiSimulator::class);
    config([
        'integrations.outbound.allow_loopback' => true,
        'integrations.endpoints.overrides' => $this->scenario->simulator()->appConfiguration($this->scenario)['env']['INTEGRATION_ENDPOINTS'],
        'integrations.endpoints.ca_bundle' => $this->scenario->ca()->bundlePath(),
    ]);
    app()->forgetInstance(Endpoints::class);
    nepalGateway(KhaltiDriver::KEY, ['sandbox' => '1', 'secret_key' => KhaltiSimulator::SECRET_KEY]);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

function khaltiBrowser(): PendingRequest
{
    return Http::withOptions(['verify' => test()->scenario->ca()->bundlePath(), 'allow_redirects' => false]);
}

/**
 * The client starts paying a fresh invoice of 1,130.55 (paisa 113_055); returns the invoice, the attempt and the
 * simulator's payment page the app redirected to.
 *
 * @return array{0: Invoice, 1: PaymentAttempt, 2: string}
 */
function khaltiStart(int $amount = 113_055, ?Client $client = null): array
{
    $invoice = $client === null ? nprInvoice([$amount])[1] : issueInvoice($client, [$amount]);
    $page = (string) test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->headers->get('Location');
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();

    expect($page)->toBe(test()->scenario->apiUrl().'/?pidx='.$attempt->provider_ref);

    return [$invoice, $attempt, $page];
}

/** The payer's choice on the hosted page; returns the return URL Khalti sends the browser to. */
function khaltiChoose(PaymentAttempt $attempt, string $choice): string
{
    $answer = khaltiBrowser()->post(test()->scenario->apiUrl().'/pay/'.$attempt->provider_ref.'/'.$choice);
    expect($answer->status())->toBe(303);

    return (string) $answer->header('Location');
}

function khaltiConfig(string $secret = KhaltiSimulator::SECRET_KEY): GatewayConfig
{
    return new GatewayConfig(KhaltiDriver::KEY, 'Khalti', ['sandbox' => '1', 'secret_key' => $secret], sandbox: true);
}

test('a payment on the hosted page settles from the lookup, in paisa, with the fee', function () {
    [$invoice, $attempt, $page] = khaltiStart();

    expect(khaltiBrowser()->get($page)->body())->toContain('Rs 1,130.55');
    $this->get(khaltiChoose($attempt, 'pay'))->assertRedirect(route('client.invoices.show', $invoice->id));

    $payment = Transaction::query()->where('gateway', KhaltiDriver::KEY)->sole();
    $session = $this->scenario->state()->get('payments', $attempt->provider_ref);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($payment->amount_in)->toBe(113_055)
        ->and($payment->fee)->toBe(3_391)
        ->and($payment->gateway_txn_id)->toBe($session['transaction_id'])
        ->and($this->scenario->log()->count('initiate'))->toBe(1)
        ->and($this->scenario->log()->entries('initiate')[0]['input']['amount'])->toBe(113_055);
});

test('a duplicate return and a second Pay book the payment once, with no credit', function () {
    [$invoice, $attempt] = khaltiStart();
    $return = khaltiChoose($attempt, 'pay');

    $this->get($return);
    $this->get($return);
    $this->get(khaltiChoose($attempt, 'pay'));

    expect(Transaction::query()->count())->toBe(1)
        ->and(CreditLedgerEntry::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded);
});

test('a cancel on the hosted page ends the attempt without paying', function () {
    [$invoice, $attempt] = khaltiStart();

    $this->get(khaltiChoose($attempt, 'cancel'));

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Failed)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0);
});

test('a Pending payment waits, then the poller books it once Khalti completes it', function () {
    [$invoice, $attempt] = khaltiStart();

    $this->get(khaltiChoose($attempt, 'pending'));
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);

    $this->scenario->simulator()->actions()['complete'](['pidx' => $attempt->provider_ref], $this->scenario);
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)->and(Transaction::query()->count())->toBe(1);
});

test('a Pending payment that Khalti expires closes the attempt without paying', function () {
    [$invoice, $attempt] = khaltiStart();

    $this->get(khaltiChoose($attempt, 'pending'));
    $this->scenario->simulator()->actions()['expire'](['pidx' => $attempt->provider_ref], $this->scenario);
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0);
});

test('a payment for another amount is refused and goes to staff review', function () {
    [$invoice, $attempt] = khaltiStart();

    $redirect = $this->scenario->simulator()->actions()['pay'](['pidx' => $attempt->provider_ref, 'total_amount' => 13_055], $this->scenario)['redirect'];
    $this->get($redirect);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toContain('Amount mismatch');
});

test('a forged return claiming Completed pays nothing: the lookup of the stored pidx decides', function () {
    [$invoice, $attempt] = khaltiStart();

    $this->get(route('billing.gateways.return', [KhaltiDriver::KEY, 'pidx' => 'ForgedPidx', 'status' => 'Completed', 'transaction_id' => 'FAKE', 'purchase_order_id' => $attempt->reference, 'total_amount' => '113055']));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Pending)
        ->and(array_column(array_column($this->scenario->log()->entries('lookup'), 'input'), 'pidx'))->each->toBe($attempt->provider_ref);
});

test('a lookup that times out while the customer waits is left to the poller, which settles it', function () {
    config(['payments_nepal.http.interactive_timeout_seconds' => 2]);
    [$invoice, $attempt] = khaltiStart();
    $return = khaltiChoose($attempt, 'pay');
    $this->scenario->faults()->add('lookup', Fault::commitThenHang(4), nth: 1);

    $this->get($return);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)->and($attempt->fresh()->status)->toBe(AttemptStatus::Pending);

    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)->and(Transaction::query()->count())->toBe(1);
});

test('a full refund goes to Khalti once; refunded and partially refunded sessions look up as such', function () {
    [, $attempt] = khaltiStart();
    $this->get(khaltiChoose($attempt, 'pay'));
    $payment = Transaction::query()->where('gateway', KhaltiDriver::KEY)->sole();

    app(RefundTransaction::class)->handle($payment, 113_055, RefundMode::Gateway);

    $driver = app(KhaltiDriver::class);
    expect($this->scenario->log()->count('refund'))->toBe(1)
        ->and($this->scenario->log()->entries('refund')[0]['input'])->toBe([])
        ->and($driver->lookup(khaltiConfig(), $attempt->fresh()->toRef())->status)->toBe(LookupResult::REFUNDED);

    [, $second] = khaltiStart(50_000, Client::query()->sole());
    $this->get(khaltiChoose($second, 'pay'));
    $this->scenario->simulator()->actions()['refund'](['pidx' => $second->provider_ref, 'amount' => 10_000], $this->scenario);
    expect($driver->lookup(khaltiConfig(), $second->fresh()->toRef())->status)->toBe(LookupResult::PARTIALLY_REFUNDED);
});

test('Test connection accepts the simulator key and names a rejected one', function () {
    $driver = app(KhaltiDriver::class);

    expect($driver->testConnection(khaltiConfig())->ok)->toBeTrue()
        ->and($driver->testConnection(khaltiConfig('test_secret_key_wrong'))->ok)->toBeFalse()
        ->and($driver->testConnection(khaltiConfig('test_secret_key_wrong'))->message)->toContain('rejected the secret key');
});
