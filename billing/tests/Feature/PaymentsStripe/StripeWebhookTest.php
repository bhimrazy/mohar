<?php

use App\Events\Billing\InvoicePaid;
use App\Events\Billing\PaymentReversed;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\GatewayCallbackHandler;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Modules\PaymentsStripe\Support\WebhookSignature;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

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

test('refund webhooks for a refund not yet succeeded, or of a payment not made here, book nothing', function (array $refund) {
    paidStripeAttempt();
    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertOk();
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', $refund))]);

    postStripeWebhook(stripeEvent('refund.created', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0005'))->assertOk()->assertJson(['result' => 'ignored event type']);

    expect(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(0);
})->with([
    'pending' => [['status' => 'pending']],
    'of a payment not made here' => [['payment_intent' => ['metadata' => ['mohar_reference' => null]]]],
]);

test('a dispute is recorded once as a chargeback, moves the invoice to Collections and is in the gateway log for staff', function () {
    Event::fake([PaymentReversed::class]);
    [$invoice] = paidStripeAttempt();
    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertOk();
    Http::fake([STRIPE_API.'disputes/du_1MoharDispute0001*' => Http::response(stripeFixture('dispute'))]);

    postStripeWebhook(stripeEvent('charge.dispute.created', 'du_1MoharDispute0001', eventId: 'evt_1MoharEvent0006'))->assertOk()->assertJson(['result' => 'chargeback recorded']);
    postStripeWebhook(stripeEvent('charge.dispute.created', 'du_1MoharDispute0001', eventId: 'evt_1MoharEvent0006'))->assertOk()->assertJson(['result' => 'duplicate']);

    expect(Transaction::query()->where('type', TransactionType::Chargeback)->sole())->gateway_txn_id->toBe('du_1MoharDispute0001')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Collections)
        ->and(GatewayLog::query()->where('gateway', StripeDriver::KEY)->where('direction', 'callback')->where('result', 'chargeback recorded')->sole()->invoice_id)->toBe($invoice->id);
    Event::assertDispatchedTimes(PaymentReversed::class, 1);
});

test('a dispute that arrives before its payment is booked is refused for a retry, then booked as a chargeback once (#124)', function () {
    Event::fake([PaymentReversed::class]);
    [$invoice] = paidStripeAttempt();
    Http::fake([STRIPE_API.'disputes/du_1MoharDispute0001*' => Http::response(stripeFixture('dispute'))]);
    $dispute = stripeEvent('charge.dispute.created', 'du_1MoharDispute0001', eventId: 'evt_1MoharEvent0007');

    postStripeWebhook($dispute)->assertStatus(409)->assertJson(['result' => GatewayCallbackHandler::PAYMENT_NOT_BOOKED]);
    expect(Transaction::query()->where('type', TransactionType::Chargeback)->count())->toBe(0)
        ->and(GatewayLog::query()->where('direction', 'callback')->latest('id')->first()->result)->toBe('payment not booked yet; retry expected');

    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertOk()->assertJson(['result' => 'payment recorded']);
    postStripeWebhook($dispute)->assertOk()->assertJson(['result' => 'chargeback recorded']);
    // Stripe may still deliver its own retry of the refused event.
    postStripeWebhook($dispute)->assertOk()->assertJson(['result' => 'duplicate']);

    expect(Transaction::query()->where('type', TransactionType::Chargeback)->count())->toBe(1)
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
