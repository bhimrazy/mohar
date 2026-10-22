<?php

use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\GatewayCallbackHandler;
use App\Modules\Core\Support\Settings;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Support\Integrations\Endpoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\Stripe\StripeSimulator;
use Tests\TestCase;

require_once dirname(__DIR__, 4).'/tests/Feature/PaymentsStripe/helpers.php';

uses(TestCase::class, RefreshDatabase::class);

/**
 * The PaymentsStripe driver, unchanged, against the stripe simulator (ADR 0016 §3-4): the app reaches it through
 * Endpoints overrides (verified against the scenario CA, pinned by the outbound guard), the customer's browser uses the
 * hosted page, and webhooks arrive with the exact bytes and signature the simulator produced. No Http::fake.
 */
beforeEach(function () {
    $this->scenario = Processes::start(StripeSimulator::class);
    config([
        'integrations.outbound.allow_loopback' => true,
        'integrations.endpoints.overrides' => $this->scenario->simulator()->appConfiguration($this->scenario)['env']['INTEGRATION_ENDPOINTS'],
        'integrations.endpoints.ca_bundle' => $this->scenario->ca()->bundlePath(),
    ]);
    app()->forgetInstance(Endpoints::class);
    stripeGateway(['secret_key' => StripeSimulator::SECRET_KEY, 'webhook_secret' => StripeSimulator::WEBHOOK_SECRET]);
    $this->scenario->simulator()->actions()['webhook_url'](['url' => route('billing.webhooks', StripeDriver::KEY)], $this->scenario);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

/** The customer's browser: trusts the scenario CA and shows us each redirect instead of following it. */
function browser(): PendingRequest
{
    return Http::withOptions(['verify' => test()->scenario->ca()->bundlePath(), 'allow_redirects' => false]);
}

/**
 * The client starts paying the invoice; returns the hosted checkout URL the app redirected to.
 */
function checkoutFor(Invoice $invoice): string
{
    $location = (string) test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => StripeDriver::KEY])->headers->get('Location');

    expect($location)->toStartWith(test()->scenario->apiUrl().'/c/pay/cs_test_');

    return strstr($location, '#', true) ?: $location;
}

/**
 * Deliver every queued webhook to the app as the simulator signed it. Returns the app's answers, in order.
 *
 * @return list<array{0: int, 1: string}> HTTP status and `result`
 */
function deliverWebhooks(bool $newestFirst = false, ?string $variant = null, bool $replay = false): array
{
    $outbox = test()->scenario->outbox();
    $deliveries = $replay ? test()->replay : $outbox->pending($newestFirst);
    test()->replay = $deliveries;
    $answers = [];

    foreach ($deliveries as $delivery) {
        $outbox->deliver($delivery, function (string $url, string $body, array $headers) use (&$answers): int {
            $response = test()->call('POST', $url, server: ['HTTP_STRIPE_SIGNATURE' => $headers['Stripe-Signature'], 'CONTENT_TYPE' => $headers['Content-Type']], content: $body);
            $answers[] = [$response->getStatusCode(), (string) $response->json('result')];

            return $response->getStatusCode();
        }, $variant);
    }

    return $answers;
}

test('a customer pays on the hosted page; signed webhooks and lookup settle it once, whatever the order or repeats', function () {
    [, $invoice] = usdInvoice([2_500]);
    $checkout = checkoutFor($invoice);

    expect(browser()->get($checkout)->body())->toContain('25.00 USD');
    $paid = browser()->post($checkout.'/pay');
    expect($paid->status())->toBe(303)->and($paid->header('Location'))->toStartWith(route('billing.gateways.return', StripeDriver::KEY));

    // The customer closes the tab before the return: the webhooks alone settle it, delivered out of order.
    $answers = deliverWebhooks(newestFirst: true);
    expect(array_column($answers, 0))->each->toBe(200)
        ->and(array_column($answers, 1))->toContain('payment recorded')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    // Replays of every event, then the late browser return: nothing more is booked.
    expect(array_column(deliverWebhooks(replay: true), 1))->each->toBe('duplicate');
    $this->get($paid->header('Location'))->assertRedirect(route('client.invoices.show', $invoice->id));

    $payment = Transaction::query()->where('gateway', StripeDriver::KEY)->sole();
    expect($payment->type)->toBe(TransactionType::Payment)
        ->and($payment->amount_in)->toBe(2_500)
        ->and($payment->fee)->toBe(103)
        ->and($payment->gateway_txn_id)->toStartWith('pi_')
        ->and($this->scenario->state()->get('payment_intents', $payment->gateway_txn_id)['status'])->toBe('succeeded');
});

test('a forged or stale webhook is refused before anything is retrieved or booked', function () {
    [, $invoice] = usdInvoice([2_500]);
    browser()->post(checkoutFor($invoice).'/pay');
    $retrieves = fn (): int => $this->scenario->log()->count('retrieve_checkout_session') + $this->scenario->log()->count('retrieve_payment_intent');

    foreach (['wrong_secret', 'stale'] as $variant) {
        expect(array_column(deliverWebhooks(variant: $variant, replay: $variant === 'stale'), 0))->each->toBeGreaterThanOrEqual(400);
    }

    expect($retrieves())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0);
});

test('a staff refund goes to Stripe once, and its webhook books nothing more', function () {
    [, $invoice] = usdInvoice([2_500]);
    browser()->post(checkoutFor($invoice).'/pay');
    deliverWebhooks();
    $payment = Transaction::query()->where('gateway', StripeDriver::KEY)->sole();

    app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);
    $refund = Transaction::query()->where('type', TransactionType::Refund)->sole();
    $atStripe = $this->scenario->state()->get('refunds', $refund->gateway_txn_id);

    expect($atStripe)->toMatchArray(['amount' => 1_000, 'payment_intent' => $payment->gateway_txn_id, 'status' => 'succeeded'])
        ->and($atStripe['metadata'])->toMatchArray(['mohar_origin' => 'admin', 'mohar_refund' => (string) $refund->id])
        ->and($this->scenario->state()->get('charges', $this->scenario->state()->get('payment_intents', $payment->gateway_txn_id)['latest_charge'])['amount_refunded'])->toBe(1_000)
        ->and(array_column(deliverWebhooks(), 1))->toContain('duplicate')
        ->and(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->scenario->log()->count('create_refund'))->toBe(1);
});

test('a staff refund whose answer times out after Stripe made it stays pending, blocks a second refund, and its webhook settles the same row (#334)', function () {
    app(Settings::class)->setMany(['billing.gateways.http_timeout_seconds' => 3]);
    [, $invoice] = usdInvoice([2_500]);
    browser()->post(checkoutFor($invoice).'/pay');
    deliverWebhooks();
    $payment = Transaction::query()->where('gateway', StripeDriver::KEY)->sole();
    $this->scenario->faults()->add('create_refund', Fault::commitThenHang(5), nth: 1);

    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);

    // Stripe refunded; our call timed out. The refund waits, the amount stays reserved.
    expect($pending->status)->toBe(TransactionStatus::Pending)->and($pending->awaitsProviderConfirmation())->toBeTrue()
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500)
        ->and($this->scenario->log()->count('create_refund'))->toBe(1);

    // A retry would be a new refund with a new idempotency key: it never reaches Stripe.
    expect(fn () => app(RefundTransaction::class)->handle($payment->fresh(), 1_000, RefundMode::Gateway))->toThrow(BillingException::class, 'being confirmed with the provider')
        ->and($this->scenario->log()->count('create_refund'))->toBe(1);

    expect(array_column(deliverWebhooks(), 1))->toContain('refund recorded');
    expect(Transaction::query()->where('type', TransactionType::Refund)->sole())->id->toBe($pending->id)->status->toBe(TransactionStatus::Succeeded)->amount_out->toBe(1_000)
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500)
        ->and($invoice->fresh()->amount_paid)->toBe(1_500);
});

test('Check refund settles a refund Stripe made behind a timeout, and releases one Stripe never received (#334)', function () {
    app(Settings::class)->setMany(['billing.gateways.http_timeout_seconds' => 3]);
    [, $invoice] = usdInvoice([2_500]);
    browser()->post(checkoutFor($invoice).'/pay');
    deliverWebhooks();
    $payment = Transaction::query()->where('gateway', StripeDriver::KEY)->sole();
    $this->scenario->faults()->add('create_refund', Fault::commitThenHang(5), nth: 1);
    $this->scenario->faults()->add('create_refund', Fault::resetBeforeCommit(), nth: 2);

    $made = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);
    expect($made->status)->toBe(TransactionStatus::Pending);

    $settled = app(RefundTransaction::class)->check($made);
    expect($settled->status)->toBe(TransactionStatus::Succeeded)->and($settled->gateway_txn_id)->toStartWith('re_')
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500);

    // The connection dropped before Stripe stored anything: the check finds no such refund and frees the amount.
    $lost = app(RefundTransaction::class)->handle($payment->fresh(), 500, RefundMode::Gateway);
    expect($lost->status)->toBe(TransactionStatus::Pending)->and($payment->fresh()->refundable_remaining)->toBe(1_000);
    expect(app(RefundTransaction::class)->check($lost)->status)->toBe(TransactionStatus::Failed)
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500);
});

test('a dispute raised at Stripe is recorded as a chargeback, once', function () {
    [, $invoice] = usdInvoice([2_500]);
    browser()->post(checkoutFor($invoice).'/pay');
    deliverWebhooks();
    $payment = Transaction::query()->where('gateway', StripeDriver::KEY)->sole();

    $this->scenario->simulator()->actions()['dispute'](['payment_intent' => $payment->gateway_txn_id, 'reason' => 'fraudulent'], $this->scenario);

    // charge.dispute.created books it; charge.dispute.funds_withdrawn, which Stripe sends next, is a duplicate.
    expect(array_column(deliverWebhooks(), 1))->toBe(['chargeback recorded', 'duplicate'])
        ->and(array_column(deliverWebhooks(replay: true), 1))->toBe(['duplicate', 'duplicate'])
        ->and(Transaction::query()->where('type', TransactionType::Chargeback)->sole()->gateway_txn_id)->toStartWith('du_')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Collections);
});

test('an inquiry raised at Stripe books a zero-amount to-do and no money, and its close changes nothing (#212)', function () {
    [, $invoice] = usdInvoice([2_500]);
    browser()->post(checkoutFor($invoice).'/pay');
    deliverWebhooks();
    $payment = Transaction::query()->where('gateway', StripeDriver::KEY)->sole();
    $actions = $this->scenario->simulator()->actions();

    $dispute = $actions['dispute'](['payment_intent' => $payment->gateway_txn_id, 'inquiry' => true], $this->scenario)['dispute'];

    expect(array_column(deliverWebhooks(), 1))->toBe(['dispute inquiry recorded; no money moved'])
        ->and(array_column(deliverWebhooks(replay: true), 1))->toBe(['duplicate']);

    $actions['close_dispute'](['dispute' => $dispute, 'status' => 'warning_closed'], $this->scenario);

    expect(array_column(deliverWebhooks(), 1))->toBe(['ignored event type'])
        ->and(Transaction::query()->where('type', TransactionType::DisputeInquiry)->sole())->amount_in->toBe(0)->amount_out->toBe(0)
        ->and(Transaction::query()->where('type', TransactionType::Chargeback)->exists())->toBeFalse()
        ->and($payment->fresh()->refundable_remaining)->toBe(2_500)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('an inquiry that escalates and is won books the chargeback and its reversal when funds_withdrawn is subscribed (#232)', function () {
    [, $invoice] = usdInvoice([2_500]);
    browser()->post(checkoutFor($invoice).'/pay');
    deliverWebhooks();
    $payment = Transaction::query()->where('gateway', StripeDriver::KEY)->sole();
    $actions = $this->scenario->simulator()->actions();
    $dispute = $actions['dispute'](['payment_intent' => $payment->gateway_txn_id, 'inquiry' => true], $this->scenario)['dispute'];
    deliverWebhooks();

    $actions['escalate_dispute'](['dispute' => $dispute], $this->scenario);
    expect(array_column(deliverWebhooks(), 1))->toBe(['chargeback recorded'])
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Collections);

    $actions['close_dispute'](['dispute' => $dispute, 'status' => 'won'], $this->scenario);
    // funds_reinstated is not subscribed to by the driver; the close books the win.
    expect(array_column(deliverWebhooks(), 1))->toBe(['ignored event type', 'dispute won; payment reinstated'])
        ->and(Transaction::query()->where('type', TransactionType::ChargebackReversal)->sole()->amount_in)->toBe(2_500)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('an inquiry that escalates and is won on an install without funds_withdrawn is acknowledged and moves no money (#232)', function () {
    $this->scenario->simulator()->actions()['webhook_url'](['url' => route('billing.webhooks', StripeDriver::KEY), 'enabled_events' => [
        'checkout.session.completed', 'payment_intent.succeeded', 'charge.dispute.created', 'charge.dispute.closed',
    ]], $this->scenario);
    [, $invoice] = usdInvoice([2_500]);
    browser()->post(checkoutFor($invoice).'/pay');
    deliverWebhooks();
    $payment = Transaction::query()->where('gateway', StripeDriver::KEY)->sole();
    $actions = $this->scenario->simulator()->actions();
    $dispute = $actions['dispute'](['payment_intent' => $payment->gateway_txn_id, 'inquiry' => true], $this->scenario)['dispute'];
    expect(array_column(deliverWebhooks(), 1))->toBe(['dispute inquiry recorded; no money moved']);

    $actions['escalate_dispute'](['dispute' => $dispute], $this->scenario);
    expect(deliverWebhooks())->toBe([]);

    $actions['close_dispute'](['dispute' => $dispute, 'status' => 'won'], $this->scenario);
    expect(deliverWebhooks())->toBe([[200, GatewayCallbackHandler::DISPUTE_WON_UNBOOKED]])
        ->and(deliverWebhooks(replay: true))->toBe([[200, 'duplicate']])
        ->and($this->scenario->outbox()->pending())->toBe([])
        ->and(Transaction::query()->where('type', TransactionType::Chargeback)->exists())->toBeFalse()
        // The zero-amount marker that the dispute is won (#256 review B1).
        ->and(Transaction::query()->where('type', TransactionType::ChargebackReversal)->sole()->amount_in)->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($payment->fresh()->refundable_remaining)->toBe(2_500);
});

test('a dispute delivered before the payment is booked gets a 409, and its retry is booked as a chargeback (#124)', function () {
    [, $invoice] = usdInvoice([2_500]);
    browser()->post(checkoutFor($invoice).'/pay');
    $intent = (string) $this->scenario->state()->get('sessions', PaymentAttempt::query()->sole()->provider_ref)['payment_intent'];
    $this->scenario->simulator()->actions()['dispute'](['payment_intent' => $intent], $this->scenario);

    // As in the live sandbox: the dispute arrives before the payment's own events.
    $answers = deliverWebhooks(newestFirst: true);
    expect($answers[0])->toBe([409, 'payment not booked yet; retry expected'])
        ->and(array_column($answers, 1))->toContain('payment recorded');

    // The outbox retries the refused dispute, as Stripe does; now it is booked.
    expect(deliverWebhooks())->toBe([[200, 'chargeback recorded'], [200, 'duplicate']])
        ->and(Transaction::query()->where('type', TransactionType::Chargeback)->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Collections);
});

test('a declined card and then an abandoned checkout leave the invoice unpaid, with the closed attempt on record', function () {
    [, $invoice] = usdInvoice([2_500]);
    $checkout = checkoutFor($invoice);

    expect(browser()->post($checkout.'/decline')->status())->toBe(402);
    $back = browser()->post($checkout.'/back');
    expect($back->status())->toBe(303);

    // The browser comes back on the cancel URL: the driver expires the session at Stripe, and the lookup closes it.
    $this->get($back->header('Location'))->assertRedirect(route('client.invoices.show', $invoice->id));
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
    $session = $this->scenario->state()->get('sessions', $attempt->provider_ref);

    expect($session['status'])->toBe('expired')
        ->and($this->scenario->state()->get('payment_intents', $session['payment_intent'])['last_payment_error']['code'])->toBe('card_declined')
        ->and($attempt->status)->toBe(AttemptStatus::Expired)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0)
        ->and(browser()->get($checkout)->status())->toBe(410);

    expect(array_column(deliverWebhooks(), 0))->each->toBe(200)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

test('a payment completed at Stripe after we closed the attempt is booked once from its signed webhooks (#130)', function () {
    [, $invoice] = usdInvoice([2_500]);
    $checkout = checkoutFor($invoice);
    expect(browser()->post($checkout.'/pay')->status())->toBe(303);

    // Our side closed the attempt before Stripe's events arrived (e.g. our expiry ran first).
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
    $attempt->update(['status' => AttemptStatus::Expired]);

    $answers = deliverWebhooks();
    expect(array_column($answers, 0))->each->toBe(200)
        ->and(array_column($answers, 1))->toContain('late payment recorded')
        ->and(array_column(deliverWebhooks(replay: true), 1))->each->toBe('duplicate')
        ->and(Transaction::query()->where('gateway', StripeDriver::KEY)->sole()->amount_in)->toBe(2_500)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->late_payment_at->not->toBeNull();
});

test('after a declined card the client continues the open session, then uses another method, which expires it first (#123)', function () {
    [, $invoice] = usdInvoice([2_500]);
    $checkout = checkoutFor($invoice);
    expect(browser()->post($checkout.'/decline')->status())->toBe(402);
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();

    // Continue payment: back to the same open session, with Stripe's own URL read from the session now.
    $resume = $this->get(route('client.invoices.attempts.resume', [$invoice->id, $attempt->reference]))->assertRedirect();
    expect(strstr((string) $resume->headers->get('Location'), '#', true))->toBe($checkout)
        ->and(browser()->get($checkout)->status())->toBe(200);

    // Use another method: the session is expired at Stripe, then the lookup frees the invoice.
    $this->post(route('client.invoices.attempts.close', [$invoice->id, $attempt->reference]))
        ->assertRedirect(route('client.invoices.show', $invoice->id))->assertSessionHasNoErrors();

    expect($this->scenario->state()->get('sessions', $attempt->provider_ref)['status'])->toBe('expired')
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Expired)
        ->and(browser()->get($checkout)->status())->toBe(410)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);

    // A second click changes nothing; the expired-session webhook books nothing.
    $this->post(route('client.invoices.attempts.close', [$invoice->id, $attempt->reference]))->assertSessionHasNoErrors();
    expect(array_column(deliverWebhooks(), 0))->each->toBe(200)
        ->and(Transaction::query()->count())->toBe(0);

    // A new payment can start at once.
    expect(checkoutFor($invoice))->not->toBe($checkout);
});
