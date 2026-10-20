<?php

use App\Events\Billing\InvoicePaid;
use App\Events\Billing\PaymentReversed;
use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Modules\PaymentsStripe\Support\WebhookSignature;
use App\Support\Dashboard\Dashboard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    stripeGateway();
});

/**
 * A started Stripe payment whose PaymentIntent Stripe reports as succeeded.
 *
 * @param  array<string, mixed>  $intent  PaymentIntent fields to override
 * @param  array<string, mixed>  $session  Checkout Session fields to override
 * @return array{0: Invoice, 1: PaymentAttempt}
 */
function paidStripeAttempt(array $intent = [], array $session = [], int $intentStatus = 200): array
{
    [, $invoice, $attempt] = startStripe();
    $metadata = ['mohar_reference' => $attempt->reference, 'mohar_invoice_id' => (string) $invoice->id];
    Http::fake([
        STRIPE_API.'payment_intents/'.STRIPE_INTENT => $intentStatus === 200
            ? Http::response(stripeFixture('payment_intent_succeeded', array_replace_recursive(['metadata' => $metadata], $intent)))
            : Http::response(['error' => ['type' => 'api_error', 'message' => 'Something went wrong']], $intentStatus),
        STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'*' => Http::response(stripePaidSession($attempt, ['payment_intent' => $intent, ...$session])),
    ]);

    return [$invoice, $attempt];
}

function stripeRetrieves(): int
{
    return Http::recorded(fn (Request $request) => $request->method() === 'GET')->count();
}

test('a webhook is processed only with a valid, fresh signature, before anything is retrieved or stored', function (Closure $deliver, int $status, string $result) {
    [$invoice] = paidStripeAttempt();
    $before = stripeRetrieves();

    $deliver(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertStatus($status);

    expect(GatewayLog::query()->where('direction', 'callback')->latest('id')->first()->result)->toBe($result);

    if ($status !== 200) {
        expect(stripeRetrieves())->toBe($before)
            ->and(Transaction::query()->where('gateway', StripeDriver::KEY)->count())->toBe(0)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
            ->and(json_encode(GatewayLog::query()->latest('id')->first()->payload))->not->toContain(STRIPE_INTENT);
    }
})->with([
    'valid' => [fn (array $event) => postStripeWebhook($event), 200, 'payment recorded'],
    'tampered body' => [function (array $event) {
        $header = WebhookSignature::header((string) json_encode($event), STRIPE_WEBHOOK_SECRET, time());
        $event['data']['object']['id'] = 'pi_3MoharOtherIntent9';

        return postStripeWebhook($event, header: $header);
    }, 403, 'invalid signature'],
    'wrong secret' => [fn (array $event) => postStripeWebhook($event, secret: 'whsec_SomebodyElsesSecret000000'), 403, 'invalid signature'],
    'test-scheme signature only' => [fn (array $event) => postStripeWebhook($event, header: 't='.time().',v0='.WebhookSignature::sign((string) json_encode($event), STRIPE_WEBHOOK_SECRET, time())), 403, 'invalid signature'],
    'missing header' => [fn (array $event) => postStripeWebhook($event, header: ''), 403, 'invalid signature'],
    'stale (6 minutes old)' => [fn (array $event) => postStripeWebhook($event, timestamp: time() - 360), 422, 'stale signature timestamp'],
    'from the future' => [fn (array $event) => postStripeWebhook($event, timestamp: time() + 360), 422, 'stale signature timestamp'],
    'live event for a test gateway' => [fn (array $event) => postStripeWebhook([...$event, 'livemode' => true]), 422, 'event from the other Stripe mode'],
]);

test('one of several v1 signatures may match while a secret is being rolled', function () {
    paidStripeAttempt();
    $body = (string) json_encode(stripeEvent('payment_intent.succeeded', STRIPE_INTENT));
    $header = 't='.time().',v1='.WebhookSignature::sign($body, 'whsec_TheOldSecretBeingRolled0000', time()).',v1='.WebhookSignature::sign($body, STRIPE_WEBHOOK_SECRET, time());

    postStripeWebhook($body, header: $header)->assertOk();

    expect(Transaction::query()->where('gateway', StripeDriver::KEY)->count())->toBe(1);
});

test('the payment is recorded from the retrieved PaymentIntent, never from the posted body', function () {
    [$invoice] = paidStripeAttempt();

    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT, ['amount_received' => 999_999, 'currency' => 'eur', 'metadata' => ['mohar_invoice_id' => '999']]))->assertOk();

    expect(Transaction::query()->where('gateway', StripeDriver::KEY)->sole())->invoice_id->toBe($invoice->id)->amount_in->toBe(2_500)->gateway_txn_id->toBe(STRIPE_INTENT)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === STRIPE_API.'payment_intents/'.STRIPE_INTENT);
});

test('a PaymentIntent whose amount or currency differs from the attempt records nothing and goes to staff review', function (array $intent, string $reason) {
    [$invoice, $attempt] = paidStripeAttempt($intent);

    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertOk()->assertJson(['result' => 'attempt ambiguous']);

    expect(Transaction::query()->where('gateway', StripeDriver::KEY)->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toContain($reason);
})->with([
    'amount' => [['amount_received' => 2_000], 'Amount mismatch'],
    'currency' => [['currency' => 'eur'], 'Currency mismatch'],
]);

test('a webhook settles a converted payment at the rate stored on the attempt, even after the rate changed', function () {
    Currency::factory()->create(['code' => 'XTS', 'name' => 'Test units', 'prefix' => 'T ', 'decimals' => 2, 'exchange_rate' => '2.000000', 'is_default' => false]);
    Gateway::query()->where('key', StripeDriver::KEY)->update(['convert_to_currency' => 'USD']);
    [, $client] = actingAsClient(Client::factory()->create(['currency' => 'XTS']));
    $invoice = issueInvoice($client, [5_000]);
    Http::fake([STRIPE_API.'checkout/sessions' => fn (Request $request) => Http::response(stripeCreatedSession($request['client_reference_id'], $invoice->id))]);

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => StripeDriver::KEY])->assertRedirect();
    $attempt = PaymentAttempt::query()->sole();
    expect($attempt)->amount->toBe(2_500)->currency->toBe('USD')->document_amount->toBe(5_000)->and((float) $attempt->fx_rate)->toBe(0.5);

    // The rate moves before Stripe's webhook arrives: 2,500 USD would now be 6,250 XTS.
    Currency::query()->where('code', 'XTS')->update(['exchange_rate' => '2.500000']);
    $metadata = ['mohar_reference' => $attempt->reference, 'mohar_invoice_id' => (string) $invoice->id];
    Http::fake([
        STRIPE_API.'payment_intents/'.STRIPE_INTENT => Http::response(stripeFixture('payment_intent_succeeded', ['metadata' => $metadata])),
        STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'*' => Http::response(stripePaidSession($attempt)),
    ]);

    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertOk()->assertJson(['result' => 'payment recorded']);

    expect(Transaction::query()->where('gateway', StripeDriver::KEY)->sole())
        ->amount_in->toBe(5_000)->currency->toBe('XTS')->gateway_amount->toBe(2_500)->gateway_currency->toBe('USD')
        ->and((float) Transaction::query()->sole()->exchange_rate)->toBe(0.5)
        ->and($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->balance->toBe(0)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded);
});

test('replays, duplicate events, the return route and the poller record the payment once', function () {
    Event::fake([InvoicePaid::class]);
    [$invoice, $attempt] = paidStripeAttempt();

    $intentEvent = stripeEvent('payment_intent.succeeded', STRIPE_INTENT);
    postStripeWebhook($intentEvent)->assertOk()->assertJson(['result' => 'payment recorded']);
    postStripeWebhook($intentEvent)->assertOk()->assertJson(['result' => 'duplicate']);
    postStripeWebhook(stripeEvent('checkout.session.completed', STRIPE_SESSION, eventId: 'evt_1MoharEvent0002'))->assertOk()->assertJson(['result' => 'duplicate']);
    $this->get(route('billing.gateways.return', [StripeDriver::KEY, 'ref' => $attempt->reference]));
    $this->travel(10)->minutes();
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->where('gateway', StripeDriver::KEY)->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->transaction_id->toBe(Transaction::query()->sole()->id);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

test('the session webhook settles a payment whose customer never came back', function () {
    [$invoice] = paidStripeAttempt();

    postStripeWebhook(stripeEvent('checkout.session.completed', STRIPE_SESSION))->assertOk()->assertJson(['result' => 'payment recorded']);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_starts_with($request->url(), STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'?expand'));
});

test('events that move no money are acknowledged and logged', function (string $type, string $objectId) {
    [$invoice] = paidStripeAttempt();

    postStripeWebhook(stripeEvent($type, $objectId))->assertOk()->assertJson(['result' => 'ignored event type']);

    expect(Transaction::query()->count())->toBe(0)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(GatewayLog::query()->latest('id')->first()->payload['type'])->toStartWith('stripe '.$type);
})->with([
    'failed attempt inside Checkout' => ['payment_intent.payment_failed', STRIPE_INTENT],
    'charge.refunded (refund.created is used)' => ['charge.refunded', 'ch_3MoharCharge0001'],
]);

test('session events settle the attempt by lookup, whatever state it is in', function (array $session, string $result, AttemptStatus $status) {
    [$invoice, $attempt] = paidStripeAttempt(session: $session);

    postStripeWebhook(stripeEvent('checkout.session.expired', STRIPE_SESSION))->assertOk()->assertJson(['result' => $result]);

    expect($attempt->fresh()->status)->toBe($status)
        ->and(Transaction::query()->count())->toBe(0)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
})->with([
    'expired: the invoice unlocks at once' => [['status' => 'expired', 'payment_status' => 'unpaid'], 'attempt expired', AttemptStatus::Expired],
    'completed, delayed method still processing' => [['payment_status' => 'unpaid'], 'attempt pending', AttemptStatus::Pending],
]);

test('a session event for a reference we never issued is acknowledged', function () {
    paidStripeAttempt(session: ['client_reference_id' => 'never-issued-reference', 'metadata' => ['mohar_reference' => 'never-issued-reference']]);

    postStripeWebhook(stripeEvent('checkout.session.completed', STRIPE_SESSION))->assertOk()->assertJson(['result' => 'unknown attempt']);

    expect(Transaction::query()->count())->toBe(0);
});

test('Stripe being unreachable during the retrieve makes Stripe deliver the event again', function () {
    Sleep::fake();
    paidStripeAttempt(intentStatus: 500);

    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertStatus(422);

    expect(Transaction::query()->count())->toBe(0)
        ->and(Http::recorded(fn (Request $request) => str_contains($request->url(), 'payment_intents/'))->count())->toBe(3);
});

test('a refund made in the Stripe Dashboard is recorded once from refund.created', function () {
    [$invoice] = paidStripeAttempt();
    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertOk();
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund'))]);

    postStripeWebhook(stripeEvent('refund.created', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0003'))->assertOk()->assertJson(['result' => 'refund recorded']);
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0004'))->assertOk()->assertJson(['result' => 'duplicate']);

    expect(Transaction::query()->where('type', TransactionType::Refund)->sole())->amount_out->toBe(1_000)->gateway_txn_id->toBe('re_3MoharRefund0001')
        ->and(Transaction::query()->where('type', TransactionType::Payment)->sole()->refundable_remaining)->toBe(1_500)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('refund webhooks for refunds made from our admin, or not yet succeeded, book nothing', function (array $refund) {
    paidStripeAttempt();
    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertOk();
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', $refund))]);

    postStripeWebhook(stripeEvent('refund.created', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0005'))->assertOk()->assertJson(['result' => 'ignored event type']);

    expect(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(0);
})->with([
    'pending' => [['status' => 'pending']],
    'of a payment not made here' => [['payment_intent' => ['metadata' => ['mohar_reference' => null]]]],
]);

test('a dispute is recorded as a chargeback and flags the invoice for staff', function () {
    Event::fake([PaymentReversed::class]);
    [$invoice] = paidStripeAttempt();
    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertOk();
    Http::fake([STRIPE_API.'disputes/du_1MoharDispute0001*' => Http::response(stripeFixture('dispute'))]);

    postStripeWebhook(stripeEvent('charge.dispute.created', 'du_1MoharDispute0001', eventId: 'evt_1MoharEvent0006'))->assertOk()->assertJson(['result' => 'chargeback recorded']);
    postStripeWebhook(stripeEvent('charge.dispute.created', 'du_1MoharDispute0001', eventId: 'evt_1MoharEvent0006'))->assertOk()->assertJson(['result' => 'duplicate']);

    expect(Transaction::query()->where('type', TransactionType::Chargeback)->sole())->gateway_txn_id->toBe('du_1MoharDispute0001')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Collections);
    Event::assertDispatchedTimes(PaymentReversed::class, 1);
});

test('a dispute of a payment made by another integration on the account is acknowledged, not retried', function () {
    paidStripeAttempt();
    Http::fake([STRIPE_API.'disputes/du_1MoharDispute0002*' => Http::response(stripeFixture('dispute', ['id' => 'du_1MoharDispute0002', 'payment_intent' => ['id' => 'pi_3SomeoneElse00001', 'metadata' => ['mohar_reference' => null]]]))]);

    postStripeWebhook(stripeEvent('charge.dispute.created', 'du_1MoharDispute0002'))->assertOk()->assertJson(['result' => 'ignored event type']);

    expect(Transaction::query()->where('type', TransactionType::Chargeback)->count())->toBe(0);
});

test('an attempt reference only settles attempts of the gateway the webhook came in on', function () {
    paidStripeAttempt();
    $khalti = PaymentAttempt::query()->create(['reference' => 'khalti-attempt-ref-0001', 'gateway' => 'khalti', 'client_id' => PaymentAttempt::query()->sole()->client_id,
        'amount' => 2_500, 'currency' => 'NPR', 'document_amount' => 2_500, 'document_currency' => 'NPR', 'provider_ref' => 'PIDX1', 'expires_at' => now()->addHour()]);
    Http::fake([STRIPE_API.'checkout/sessions/cs_test_a1MoharSession0009*' => Http::response(stripeFixture('checkout_session_created', [
        'id' => 'cs_test_a1MoharSession0009', 'client_reference_id' => $khalti->reference, 'metadata' => ['mohar_reference' => $khalti->reference]]))]);

    postStripeWebhook(stripeEvent('checkout.session.completed', 'cs_test_a1MoharSession0009'))->assertOk()->assertJson(['result' => 'unknown attempt']);

    expect($khalti->fresh())->lookup_count->toBe(0)->status->toBe(AttemptStatus::Pending);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'khalti'));
});

test('webhook bodies over 256 KB are refused before any signature work', function () {
    paidStripeAttempt();
    $body = (string) json_encode(stripeEvent('payment_intent.succeeded', STRIPE_INTENT, ['padding' => str_repeat('x', 262_144)]));

    postStripeWebhook($body)->assertStatus(413);

    expect(GatewayLog::query()->latest('id')->first()->result)->toBe('payload too large')
        ->and(Transaction::query()->count())->toBe(0)
        ->and(stripeRetrieves())->toBe(0);
});

/**
 * Issue #130 (matrix PG-19 / M-01): Stripe confirms a payment for an attempt we already closed.
 *
 * @param  array<string, mixed>  $session  Checkout Session fields to override
 * @return array{0: Invoice, 1: PaymentAttempt}
 */
function closedStripeAttempt(AttemptStatus $closedAs = AttemptStatus::Expired, array $session = []): array
{
    [$invoice, $attempt] = paidStripeAttempt(session: $session);
    $attempt->update(['status' => $closedAs, 'expires_at' => now()->subMinute()]);

    return [$invoice, $attempt->fresh()];
}

function lateActivity(): ActivityLog
{
    return ActivityLog::query()->where('event', 'billing.payment.late')->sole();
}

test('a payment Stripe confirms for a closed attempt is booked on the still unpaid invoice and flagged for staff', function (AttemptStatus $closedAs) {
    Event::fake([InvoicePaid::class]);
    [$invoice, $attempt] = closedStripeAttempt($closedAs);

    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertOk()->assertJson(['result' => 'late payment recorded']);

    expect(Transaction::query()->where('gateway', StripeDriver::KEY)->sole())->amount_in->toBe(2_500)->gateway_txn_id->toBe(STRIPE_INTENT)
        ->and($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->balance->toBe(0)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->late_payment_at->not->toBeNull()
        ->review_reason->toBe('Paid at the provider after the attempt was '.$closedAs->value)
        ->and(lateActivity())->description->toBe(SettlePaymentAttempt::LATE_NOTE)->client_id->toBe($invoice->client_id)
        ->and(lateActivity()->properties)->toMatchArray(['closed_as' => $closedAs->value, 'booked' => true, 'applied' => 2_500, 'credited' => 0])
        ->and(collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.late_payments')['count'])->toBe(1);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
})->with([
    'expired' => [AttemptStatus::Expired],
    'cancelled or replaced' => [AttemptStatus::Failed],
]);

test('a late Stripe payment on an invoice another payment already settled becomes account credit the client can see', function () {
    [$invoice, $attempt] = closedStripeAttempt();
    payInvoice($invoice, 2_500, 'bank-ref-1');

    postStripeWebhook(stripeEvent('checkout.session.completed', STRIPE_SESSION))->assertOk()->assertJson(['result' => 'late payment recorded']);

    $payment = Transaction::query()->where('gateway', StripeDriver::KEY)->sole();
    expect($payment)->amount_in->toBe(2_500)->currency->toBe('USD')
        ->and($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->amount_paid->toBe(2_500)
        ->and(app(CreditLedgerService::class)->balance($invoice->client_id, 'USD')->amount)->toBe(2_500)
        ->and(lateActivity()->properties)->toMatchArray(['applied' => 0, 'credited' => 2_500]);

    $this->get(route('client.credit.index'))->assertInertia(fn (Assert $page) => $page
        ->where('balance', 2_500)
        ->where('transactions.data', fn ($rows) => collect($rows)->contains('id', $payment->id)));
});

test('a late Stripe payment larger than what is left on the invoice pays the balance and credits the rest', function () {
    [$invoice] = closedStripeAttempt();
    payInvoice($invoice, 1_000, 'bank-ref-2');

    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertOk()->assertJson(['result' => 'late payment recorded']);

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->balance->toBe(0)
        ->and(app(CreditLedgerService::class)->balance($invoice->client_id, 'USD')->amount)->toBe(1_000)
        ->and(lateActivity()->properties)->toMatchArray(['applied' => 1_500, 'credited' => 1_000]);
});

test('replayed webhooks, the return route and the sweep book a late Stripe payment once', function () {
    [$invoice, $attempt] = closedStripeAttempt();

    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertOk()->assertJson(['result' => 'late payment recorded']);
    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertOk()->assertJson(['result' => 'duplicate']);
    postStripeWebhook(stripeEvent('checkout.session.completed', STRIPE_SESSION, eventId: 'evt_1MoharEvent0002'))->assertOk()->assertJson(['result' => 'duplicate']);
    $this->get(route('billing.gateways.return', [StripeDriver::KEY, 'ref' => $attempt->reference]));
    $this->travel(2)->hours();
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->where('gateway', StripeDriver::KEY)->count())->toBe(1)
        ->and(ActivityLog::query()->where('event', 'billing.payment.late')->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('a forged or unsigned webhook for a closed attempt books nothing and retrieves nothing', function (Closure $deliver) {
    [$invoice, $attempt] = closedStripeAttempt();
    $before = stripeRetrieves();

    $deliver(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertForbidden();

    expect(stripeRetrieves())->toBe($before)
        ->and(Transaction::query()->count())->toBe(0)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Expired)->lookup_count->toBe($attempt->lookup_count)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
})->with([
    'wrong secret' => [fn (array $event) => postStripeWebhook($event, secret: 'whsec_SomebodyElsesSecret000000')],
    'unsigned' => [fn (array $event) => postStripeWebhook($event, header: '')],
]);

test('a closed attempt whose session Stripe reports as not paid stays closed and books nothing', function () {
    [$invoice, $attempt] = closedStripeAttempt(session: ['status' => 'expired', 'payment_status' => 'unpaid']);

    postStripeWebhook(stripeEvent('checkout.session.expired', STRIPE_SESSION))->assertOk()->assertJson(['result' => 'attempt expired']);

    expect(Transaction::query()->count())->toBe(0)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Expired)->late_payment_at->toBeNull()->lookup_count->toBe($attempt->lookup_count + 1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(ActivityLog::query()->where('event', 'billing.payment.late')->count())->toBe(0);
});
