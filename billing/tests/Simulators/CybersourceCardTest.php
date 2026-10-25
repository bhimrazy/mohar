<?php

use App\Data\Billing\GatewayConfig;
use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsCybersource\Drivers\CybersourceDriver;
use App\Support\Integrations\Endpoints;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\Cybersource\CybersourceSimulator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Simulators\SimulatorHarness;
use Tests\TestCase;

uses(TestCase::class, SimulatorHarness::class);

require_once __DIR__.'/../Feature/PaymentsNepal/helpers.php';

/**
 * The card driver against the cybersource simulator over TLS (ADR 0016 §4, gateway expansion AC 6 and 20): the signed
 * form, the hosted page, the signed return and notice, the REST lookup with HTTP Signature, refunds, a cancel, a
 * forged return and a Decision Manager review. Every module is real; Cybersource is the simulator.
 */
beforeEach(function () {
    $this->refuseAnyDatabaseButAThrowawayOne();
    $this->cards = Processes::start(CybersourceSimulator::class);
    config([
        'integrations.outbound.allow_loopback' => true,
        'integrations.endpoints.overrides' => $this->cards->simulator()->appConfiguration($this->cards)['env']['INTEGRATION_ENDPOINTS'],
        'integrations.endpoints.ca_bundle' => $this->cards->ca()->bundlePath(),
    ]);
    app()->forgetInstance(Endpoints::class);
    $this->cards->simulator()->actions()['notice_url'](['url' => route('billing.webhooks', CybersourceDriver::KEY)], $this->cards);
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    nepalGateway(CybersourceDriver::KEY, ['sandbox' => '1', 'merchant_id' => CybersourceSimulator::MERCHANT_ID, 'profile_id' => CybersourceSimulator::PROFILE_ID,
        'access_key' => CybersourceSimulator::ACCESS_KEY, 'secret_key' => CybersourceSimulator::SECRET_KEY, 'rest_key_id' => CybersourceSimulator::REST_KEY_ID, 'rest_secret' => CybersourceSimulator::REST_SECRET]);
});

afterEach(function () {
    Processes::stop($this->cards);
});

/** The customer starts a card payment and the browser posts the app's signed form to the hosted page; returns that page. */
function openCardSession(Invoice $invoice): string
{
    $test = test();
    $checkout = (string) $test->post(route('client.invoices.pay', $invoice->id), ['gateway' => CybersourceDriver::KEY])->headers->get('Location');
    $form = $test->get($checkout)->inertiaProps('form');
    $booked = Http::withOptions(['verify' => $test->cards->ca()->bundlePath(), 'allow_redirects' => false])->asForm()->post($form['action'], (array) $form['fields']);
    expect($booked->status())->toBe(303);

    return (string) $booked->header('Location');
}

/**
 * The customer picks $choice on the hosted page and the browser posts the signed result into the app with its exact
 * bytes. Returns the app's answer.
 *
 * @return TestResponse<Response>
 */
function payByCard(Invoice $invoice, string $choice): TestResponse
{
    $page = Http::withOptions(['verify' => test()->cards->ca()->bundlePath(), 'allow_redirects' => false])->post(openCardSession($invoice).'/'.$choice)->body();
    preg_match('/<form id="return" method="post" action="([^"]+)">/', $page, $action);
    preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $page, $inputs);
    $fields = array_combine(array_map('html_entity_decode', $inputs[1]), array_map('html_entity_decode', $inputs[2]));

    return postSigned(html_entity_decode($action[1]), $fields);
}

/** @return TestResponse<Response> */
function postSigned(string $url, array $fields): TestResponse
{
    return test()->call('POST', $url, $fields, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: http_build_query($fields));
}

/** Deliver the queued notices to the app; returns the app's `result` for each. */
function deliverCardNotices(): array
{
    $results = [];
    test()->cards->outbox()->drain(function (string $url, string $body, array $headers) use (&$results): int {
        parse_str($body, $fields);
        $response = test()->call('POST', $url, $fields, server: ['CONTENT_TYPE' => $headers['Content-Type']], content: $body);
        $results[] = $response->getStatusCode() >= 400 ? 'refused '.$response->getStatusCode() : (string) $response->json('result');

        return $response->getStatusCode();
    });

    return $results;
}

test('a card payment is confirmed by the REST lookup, its notice books nothing twice, and staff refund it in two parts', function () {
    Event::fake([InvoicePaid::class]);
    [, $invoice] = nprInvoice([113_000]);

    payByCard($invoice, 'accept')->assertRedirect(route('client.invoices.show', $invoice->id));
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();

    expect($attempt->status)->toBe(AttemptStatus::Succeeded)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->cards->log()->count('search'))->toBeGreaterThanOrEqual(1)
        ->and(deliverCardNotices())->toBe(['duplicate']);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);

    $payment = Transaction::query()->where('gateway', CybersourceDriver::KEY)->sole();
    app(RefundTransaction::class)->handle($payment, 13_000, RefundMode::Gateway);
    app(RefundTransaction::class)->handle($payment->fresh(), 100_000, RefundMode::Gateway);

    expect($this->cards->state()->get('txns', $payment->gateway_txn_id)['refunded'])->toBe(113_000)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded)
        ->and($this->cards->log()->count('refund'))->toBe(2);
});

test('a cancel on the hosted page ends the attempt; a forged return matches nothing', function () {
    [, $invoice] = nprInvoice([113_000]);

    payByCard($invoice, 'cancel');
    expect(PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole()->status)->toBe(AttemptStatus::Failed);

    openCardSession($invoice);
    $uuid = (string) PaymentAttempt::query()->where('invoice_id', $invoice->id)->latest('id')->firstOrFail()->provider_ref;
    $forged = $this->cards->simulator()->actions()['accept'](['transaction_uuid' => $uuid, 'variant' => 'wrong_secret'], $this->cards)['post'];

    postSigned($forged['url'], $forged['fields'])->assertRedirect(route('client.invoices.index'));
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(PaymentAttempt::query()->where('provider_ref', $uuid)->sole()->status)->toBe(AttemptStatus::Pending);
});

test('a Decision Manager review waits, and its later acceptance books the payment through the notice', function () {
    [, $invoice] = nprInvoice([113_000]);

    payByCard($invoice, 'review');
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
    expect($attempt->status)->toBe(AttemptStatus::Pending)->and($attempt->expires_at)->toBeNull();

    $this->cards->simulator()->actions()['resolve_review'](['transaction_uuid' => $attempt->provider_ref, 'outcome' => 'accept'], $this->cards);

    expect(deliverCardNotices())->toContain('payment recorded')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('test connection tells a wrong REST secret from the right one', function () {
    $driver = app(CybersourceDriver::class);
    $settings = ['sandbox' => '1', 'merchant_id' => CybersourceSimulator::MERCHANT_ID, 'profile_id' => CybersourceSimulator::PROFILE_ID, 'access_key' => CybersourceSimulator::ACCESS_KEY,
        'secret_key' => CybersourceSimulator::SECRET_KEY, 'rest_key_id' => CybersourceSimulator::REST_KEY_ID, 'rest_secret' => CybersourceSimulator::REST_SECRET];

    expect($driver->testConnection(new GatewayConfig(CybersourceDriver::KEY, 'Cards', $settings, sandbox: true))->ok)->toBeTrue()
        ->and($driver->testConnection(new GatewayConfig(CybersourceDriver::KEY, 'Cards', [...$settings, 'rest_secret' => base64_encode('wrong')], sandbox: true))->ok)->toBeFalse();
});
