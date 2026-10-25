<?php

use App\Data\Billing\RefundRequest;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    stripeGateway();
});

/**
 * A $25.00 invoice paid through Stripe Checkout and settled by the return route.
 */
function paidStripePayment(): Transaction
{
    [, $invoice, $attempt] = startStripe(2_500);
    Http::fake([STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'*' => Http::response(stripePaidSession($attempt))]);
    test()->get(route('billing.gateways.return', [StripeDriver::KEY, 'ref' => $attempt->reference]));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    return Transaction::query()->where('gateway', StripeDriver::KEY)->where('type', TransactionType::Payment)->sole();
}

test('staff refund part of a Stripe payment and then the rest, keyed and tagged by our refund rows', function () {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds' => Http::sequence()
        ->push(stripeFixture('refund', ['id' => 're_3MoharRefund0001', 'amount' => 1_000]))
        ->push(stripeFixture('refund', ['id' => 're_3MoharRefund0002', 'amount' => 1_500]))]);

    app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);
    expect($payment->invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    app(RefundTransaction::class)->handle($payment->fresh(), 1_500, RefundMode::Gateway);

    $rows = Transaction::query()->where('type', TransactionType::Refund)->orderBy('id')->get();
    $refunds = Http::recorded(fn (Request $request) => $request->method() === 'POST' && $request->url() === STRIPE_API.'refunds')->map(fn (array $pair) => $pair[0])->values();
    expect($refunds->map(fn (Request $request) => [$request['payment_intent'], $request['amount'], $request['metadata'], $request->header('Idempotency-Key')[0]])->all())->toBe([
        [STRIPE_INTENT, 1_000, ['mohar_origin' => 'admin', 'mohar_refund' => (string) $rows[0]->id], 'mohar-refund-'.$rows[0]->id],
        [STRIPE_INTENT, 1_500, ['mohar_origin' => 'admin', 'mohar_refund' => (string) $rows[1]->id], 'mohar-refund-'.$rows[1]->id],
    ])
        ->and($rows->pluck('gateway_txn_id')->all())->toBe(['re_3MoharRefund0001', 're_3MoharRefund0002'])
        ->and($payment->invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);

    // The webhook for a refund our refund action recorded books nothing more.
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', ['metadata' => ['mohar_origin' => 'admin', 'mohar_refund' => (string) $rows[0]->id]]))]);
    postStripeWebhook(stripeEvent('refund.created', 're_3MoharRefund0001'))->assertOk()->assertJson(['result' => 'duplicate']);
    expect(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(2);
});

test('a refund webhook arriving while the refund action is still recording it settles the row, and the action then books nothing more', function () {
    $payment = paidStripePayment();
    $during = null;
    Http::fake([
        STRIPE_API.'refunds' => function (Request $request) use (&$during) {
            // Stripe delivers refund.created before our POST has returned.
            Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', ['amount' => 2_500, 'metadata' => $request['metadata']]))]);
            $during = postStripeWebhook(stripeEvent('refund.created', 're_3MoharRefund0001'));

            return Http::response(stripeFixture('refund', ['amount' => 2_500, 'metadata' => $request['metadata']]));
        },
    ]);

    app(RefundTransaction::class)->handle($payment, 2_500, RefundMode::Gateway);

    $during->assertOk()->assertJson(['result' => 'refund recorded']);
    postStripeWebhook(stripeEvent('refund.created', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0009'))->assertOk()->assertJson(['result' => 'duplicate']);
    expect(Transaction::query()->where('type', TransactionType::Refund)->sole())->status->toBe(TransactionStatus::Succeeded)->amount_out->toBe(2_500);
});

test('a staff refund that timed out stays pending with its amount reserved, and its webhook settles that same row', function () {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds' => fn () => throw new ConnectionException('timed out')]);

    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);
    expect($pending->status)->toBe(TransactionStatus::Pending)->and($pending->awaitsProviderConfirmation())->toBeTrue()
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500);

    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', ['metadata' => ['mohar_origin' => 'admin', 'mohar_refund' => (string) $pending->id]]))]);
    postStripeWebhook(stripeEvent('refund.created', 're_3MoharRefund0001'))->assertOk()->assertJson(['result' => 'refund recorded']);
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0010'))->assertOk()->assertJson(['result' => 'duplicate']);

    expect(Transaction::query()->where('type', TransactionType::Refund)->sole())->id->toBe($pending->id)->status->toBe(TransactionStatus::Succeeded)->amount_out->toBe(1_000)->gateway_txn_id->toBe('re_3MoharRefund0001')
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500)
        ->and($payment->invoice->fresh()->amount_paid)->toBe(1_500);
});

test('a failed Stripe refund we never recorded books nothing and is logged', function () {
    paidStripePayment();
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', ['status' => 'failed', 'metadata' => ['mohar_origin' => 'admin', 'mohar_refund' => '99']]))]);

    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001'))->assertOk()->assertJson(['result' => 'failed refund not recorded here; nothing changed']);

    expect(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(0)
        ->and(GatewayLog::query()->latest('id')->first()->payload['status'])->toBe('failed');
});

test('a refund Stripe refuses leaves the payment refundable', function (Closure $response, string $message) {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds' => $response]);

    expect(fn () => app(RefundTransaction::class)->handle($payment, 2_500, RefundMode::Gateway))->toThrow(BillingException::class, $message)
        ->and($payment->fresh()->refundable_remaining)->toBe(2_500)
        ->and(Transaction::query()->where('type', TransactionType::Refund)->sole()->status)->toBe(TransactionStatus::Failed)
        ->and($payment->invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
})->with([
    'refused' => [fn () => Http::response(stripeFixture('error_already_refunded'), 400), 'has already been refunded'],
    'failed' => [fn () => Http::response(stripeFixture('refund', ['status' => 'failed'])), 'Stripe refund failed'],
]);

test('a refund Stripe never answers, or answers with a server error, stays pending and blocks a second one', function (Closure $response) {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds' => $response]);

    $pending = app(RefundTransaction::class)->handle($payment, 2_500, RefundMode::Gateway);

    expect($pending->status)->toBe(TransactionStatus::Pending)
        ->and($payment->fresh()->refundable_remaining)->toBe(0)
        ->and($payment->invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    // The amount is held back, so a retry has nothing to refund and never reaches Stripe.
    expect(fn () => app(RefundTransaction::class)->handle($payment->fresh(), 2_500, RefundMode::Gateway))->toThrow(BillingException::class)
        ->and(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(1);
})->with([
    'no answer' => [fn () => throw new ConnectionException('timed out')],
    'server error' => [fn () => Http::response(['error' => ['type' => 'api_error', 'message' => 'oops']], 500)],
]);

test('Check refund repeats the original request with the same idempotency key while Stripe still remembers it', function () {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds' => Http::sequence()->pushFailedConnection('timed out')->push(stripeFixture('refund', ['id' => 're_3MoharRefund0001', 'amount' => 1_000]))]);

    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);
    expect($pending->status)->toBe(TransactionStatus::Pending);

    $settled = app(RefundTransaction::class)->check($pending);

    $posts = Http::recorded(fn (Request $request) => $request->method() === 'POST' && $request->url() === STRIPE_API.'refunds')->map(fn (array $pair) => $pair[0])->values();
    expect($posts)->toHaveCount(2)
        ->and($posts[1]->header('Idempotency-Key')[0])->toBe($posts[0]->header('Idempotency-Key')[0])->toBe('mohar-refund-'.$pending->id)
        ->and((string) $posts[1]['amount'])->toBe((string) $posts[0]['amount'])->and($posts[1]['metadata'])->toBe($posts[0]['metadata'])
        ->and($settled)->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('re_3MoharRefund0001')
        ->and(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(1);
});

test('Check refund keeps a refund pending while Stripe is still processing the first request or answers with an error', function (array $body, int $code, string $status, int $remaining) {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds' => Http::sequence()->pushFailedConnection('timed out')->push($body, $code)]);
    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);

    expect(app(RefundTransaction::class)->check($pending)->status->value)->toBe($status)
        ->and($payment->fresh()->refundable_remaining)->toBe($remaining);
})->with([
    'first request still running (409)' => [['error' => ['type' => 'idempotency_error', 'message' => 'in use']], 409, 'pending', 1_500],
    'server error' => [['error' => ['type' => 'api_error']], 500, 'pending', 1_500],
    'charge already fully refunded elsewhere' => [stripeFixture('error_already_refunded'), 400, 'pending', 1_500],
]);

test('a refund lookup after the idempotency window lists the payment\'s refunds, and absence is "no such refund" only past 24 hours', function (array $body, int $status, int $hoursOld, string $expected, ?string $id) {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds*' => Http::response($body, $status)]);

    $result = app(StripeDriver::class)->lookupRefund(
        app(GatewayManager::class)->config(StripeDriver::KEY),
        new RefundRequest($payment->gateway_txn_id, Money::of(1_000, 'USD'), Money::of(2_500, 'USD'), reference: '77', sentAt: CarbonImmutable::now()->subHours($hoursOld)),
    );

    expect($result->status)->toBe($expected)->and($result->txnId)->toBe($id);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), 'payment_intent='.$payment->gateway_txn_id));
})->with([
    'found' => [['data' => [['id' => 're_3MoharRefund0009', 'status' => 'succeeded', 'amount' => 1_000, 'currency' => 'usd', 'metadata' => ['mohar_refund' => '77']]], 'has_more' => false], 200, 30, 'success', 're_3MoharRefund0009'],
    'found but failed' => [['data' => [['id' => 're_3MoharRefund0009', 'status' => 'failed', 'amount' => 1_000, 'currency' => 'usd', 'metadata' => ['mohar_refund' => '77']]], 'has_more' => false], 200, 30, 'declined', 're_3MoharRefund0009'],
    'found with another amount' => [['data' => [['id' => 're_3MoharRefund0009', 'status' => 'succeeded', 'amount' => 900, 'currency' => 'usd', 'metadata' => ['mohar_refund' => '77']]], 'has_more' => false], 200, 30, 'error', 're_3MoharRefund0009'],
    'found in another currency' => [['data' => [['id' => 're_3MoharRefund0009', 'status' => 'succeeded', 'amount' => 1_000, 'currency' => 'eur', 'metadata' => ['mohar_refund' => '77']]], 'has_more' => false], 200, 30, 'error', 're_3MoharRefund0009'],
    'absent past the window' => [['data' => [], 'has_more' => false], 200, 30, 'declined', null],
    'absent inside the window edge' => [['data' => [], 'has_more' => false], 200, 23, 'error', null],
    'absent from a partial list' => [['data' => [], 'has_more' => true], 200, 30, 'error', null],
    'server error' => [['error' => ['type' => 'api_error']], 500, 30, 'error', null],
]);

test('a refund that never left (no key, bad PaymentIntent) is a definite decline and releases the amount', function () {
    $payment = paidStripePayment();
    $payment->forceFill(['gateway_txn_id' => 'not-a-payment-intent'])->save();
    Http::fake();

    expect(fn () => app(RefundTransaction::class)->handle($payment->fresh(), 1_000, RefundMode::Gateway))->toThrow(BillingException::class);
    expect($payment->fresh()->refundable_remaining)->toBe(2_500)
        ->and(Transaction::query()->where('type', TransactionType::Refund)->sole()->status)->toBe(TransactionStatus::Failed);
    Http::assertNothingSent();
});

test('a Check replay that Stripe never processed (rejected key, no key, idempotency conflict) says nothing about the original and releases nothing', function (?int $code, bool $clearKey) {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds' => Http::sequence()->pushFailedConnection('timed out')->push($code === 400 ? ['error' => ['type' => 'idempotency_error', 'message' => 'other parameters']] : stripeFixture('error_invalid_key'), $code ?? 200)]);
    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);

    if ($clearKey) {
        $gateway = Gateway::query()->where('key', StripeDriver::KEY)->sole();
        $gateway->update(['config' => [...$gateway->config, 'secret_key' => '']]);
    }

    expect(app(RefundTransaction::class)->check($pending)->status)->toBe(TransactionStatus::Pending)
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500);
})->with([
    '401' => [401, false],
    '403' => [403, false],
    'idempotency conflict (400)' => [400, false],
    'key removed' => [null, true],
]);

test('a Check replay answering an error (400, 402, 404) releases nothing: it says nothing about the original request', function (int $code) {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds' => Http::sequence()->pushFailedConnection('timed out')->push(['error' => ['type' => 'invalid_request_error', 'message' => 'refused']], $code)]);
    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);

    expect(app(RefundTransaction::class)->check($pending)->status)->toBe(TransactionStatus::Pending)
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500);
})->with([400, 402, 404]);

test('a refund Stripe accepts as pending or requires_action is not booked: the amount stays held and the row keeps the refund id', function (string $state) {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds' => Http::response(stripeFixture('refund', ['status' => $state]))]);

    $row = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);

    expect($row)->status->toBe(TransactionStatus::Pending)->gateway_txn_id->toBe('re_3MoharRefund0001')
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500)
        ->and($payment->invoice->fresh()->amount_paid)->toBe(2_500);
})->with(['pending', 'requires_action', 'an unknown state' => 'processing']);

/**
 * Stripe as it moves one $10.00 refund (re_3MoharRefund0001) through its states: the POST and the retrieve both
 * answer the refund in `$state`, tagged with our row once it exists.
 */
function stripeRefundAt(string &$state, ?Transaction &$row): void
{
    $answer = function () use (&$state, &$row) {
        return Http::response(stripeFixture('refund', ['status' => $state, 'metadata' => $row !== null ? ['mohar_origin' => 'admin', 'mohar_refund' => (string) $row->id] : []]));
    };
    Http::fake([STRIPE_API.'refunds' => $answer, STRIPE_API.'refunds/re_3MoharRefund0001*' => $answer]);
}

test('a pending Stripe refund that later succeeds is settled on that row by refund.updated, once', function () {
    $payment = paidStripePayment();
    [$state, $row] = ['pending', null];
    stripeRefundAt($state, $row);
    $row = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);

    postStripeWebhook(stripeEvent('refund.created', 're_3MoharRefund0001'))->assertOk()->assertJson(['result' => 'ignored event type']);
    expect($row->fresh()->status)->toBe(TransactionStatus::Pending);

    $state = 'succeeded';
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0011'))->assertOk()->assertJson(['result' => 'refund recorded']);
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0012'))->assertOk()->assertJson(['result' => 'duplicate']);

    expect(Transaction::query()->where('type', TransactionType::Refund)->sole())->id->toBe($row->id)->status->toBe(TransactionStatus::Succeeded)
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500)
        ->and($payment->invoice->fresh()->amount_paid)->toBe(1_500);
});

test('a pending Stripe refund that later fails books nothing from refund.updated, and Check refund releases the amount', function () {
    $payment = paidStripePayment();
    [$state, $row] = ['pending', null];
    stripeRefundAt($state, $row);
    $row = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);

    $state = 'failed';
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001'))->assertOk()->assertJson(['result' => 'refund failed at the provider; Check refund releases it']);
    expect($row->fresh()->status)->toBe(TransactionStatus::Pending)->and($payment->fresh()->refundable_remaining)->toBe(1_500);

    // Check refund repeats the request with the same key, and Stripe answers with the refund, now failed.
    expect(app(RefundTransaction::class)->check($row->fresh())->status)->toBe(TransactionStatus::Failed)
        ->and($payment->fresh()->refundable_remaining)->toBe(2_500)
        ->and($payment->invoice->fresh()->amount_paid)->toBe(2_500);

    // Released rows keep the refund id in raw: a later failed event finds the row by our reference and changes nothing.
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0015'))->assertOk()->assertJson(['result' => 'refund already released; nothing changed']);
    expect($row->fresh()->status)->toBe(TransactionStatus::Failed)->and(Transaction::toReview()->count())->toBe(0);
});

test('Check refund settles a pending Stripe refund once Stripe reports it succeeded', function () {
    $payment = paidStripePayment();
    [$state, $row] = ['requires_action', null];
    stripeRefundAt($state, $row);
    $row = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);

    $state = 'succeeded';
    expect(app(RefundTransaction::class)->check($row->fresh()))->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('re_3MoharRefund0001')
        ->and($payment->invoice->fresh()->amount_paid)->toBe(1_500);
});

test('a Stripe refund booked as made that later fails goes on Disputes to review once, and the books stay as they are', function (string $failure) {
    $payment = paidStripePayment();
    [$state, $row] = ['pending', null];
    stripeRefundAt($state, $row);
    $row = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);
    // Before #497 a pending refund was booked as made: the same row, settled.
    app(RefundTransaction::class)->settle($row, 're_3MoharRefund0001');
    expect($row->fresh()->status)->toBe(TransactionStatus::Succeeded)->and(Transaction::toReview()->count())->toBe(0);

    $state = $failure;
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001'))->assertOk()
        ->assertJson(['result' => 'refund failed at the provider after it was booked; put on Disputes to review']);

    expect(Transaction::toReview()->sole())->id->toBe($row->id)->review_amount->toBe(1_000)->status->toBe(TransactionStatus::Succeeded)
        ->description->toBe('Provider reports conflicting outcome: failed after it was booked')
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500)
        ->and($payment->invoice->fresh()->amount_paid)->toBe(1_500);

    // Staff mark it reviewed; a replay or a second failed event does not reopen it.
    Transaction::toReview()->update(['reviewed_at' => now()]);
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001'))->assertOk()->assertJson(['result' => 'duplicate']);
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0013'))->assertOk()->assertJson(['result' => 'duplicate']);

    expect(Transaction::toReview()->count())->toBe(0)->and(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(1);
})->with(['failed', 'canceled']);

test('a refund made in the Stripe Dashboard that later fails goes on Disputes to review', function () {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::sequence()
        ->push(stripeFixture('refund', ['amount' => 2_500]))
        ->push(stripeFixture('refund', ['amount' => 2_500, 'status' => 'failed', 'failure_reason' => 'expired_or_canceled_card']))]);

    postStripeWebhook(stripeEvent('refund.created', 're_3MoharRefund0001'))->assertOk()->assertJson(['result' => 'refund recorded']);
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0014'))->assertOk()
        ->assertJson(['result' => 'refund failed at the provider after it was booked; put on Disputes to review']);

    expect(Transaction::toReview()->sole())->review_amount->toBe(2_500)->parent_transaction_id->toBe($payment->id)
        ->and($payment->invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);
});

test('a failed Stripe refund with another refund id, or of another payment, changes nothing', function () {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds' => Http::response(stripeFixture('refund'))]);
    $row = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0002*' => Http::response(stripeFixture('refund', ['id' => 're_3MoharRefund0002', 'status' => 'failed', 'metadata' => ['mohar_origin' => 'admin', 'mohar_refund' => (string) $row->id]]))]);

    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0002'))->assertOk()->assertJson(['result' => 'failed refund not recorded here; nothing changed']);

    // Our refund id, but retrieved against another PaymentIntent of this site.
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', ['status' => 'failed', 'payment_intent' => ['id' => 'pi_3MoharIntentOther'], 'metadata' => ['mohar_origin' => 'admin', 'mohar_refund' => (string) $row->id]]))]);
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0016'))->assertOk()->assertJson(['result' => 'failed refund names another payment; nothing changed']);

    expect(Transaction::toReview()->count())->toBe(0)->and($row->fresh()->status)->toBe(TransactionStatus::Succeeded);
});

test('a pending refund reported failed and then booked after all (the two deliveries raced) goes on Disputes to review', function () {
    $payment = paidStripePayment();
    [$state, $row] = ['pending', null];
    stripeRefundAt($state, $row);
    $row = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);

    $state = 'failed';
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001'))->assertOk()->assertJson(['result' => 'refund failed at the provider; Check refund releases it']);
    expect(Transaction::toReview()->count())->toBe(0);

    // The "succeeded" delivery that fetched the refund first commits its settle after the failed one.
    $state = 'succeeded';
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0017'))->assertOk()->assertJson(['result' => 'refund recorded']);

    expect(Transaction::toReview()->sole())->id->toBe($row->id)->status->toBe(TransactionStatus::Succeeded)->review_amount->toBe(1_000);

    $state = 'failed';
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0018'))->assertOk()->assertJson(['result' => 'duplicate']);
});

test('a booked credit refund that Stripe reports failed goes on Disputes to review, and an earlier review note is kept', function () {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds' => Http::response(stripeFixture('refund'))]);
    $row = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);
    $row->forceFill(['purpose' => Transaction::PURPOSE_CREDIT_REFUND, 'review_amount' => 200, 'description' => 'Part beyond the cap'])->save();
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', ['status' => 'failed', 'metadata' => ['mohar_origin' => 'admin', 'mohar_refund' => (string) $row->id]]))]);

    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001'))->assertOk()
        ->assertJson(['result' => 'refund failed at the provider after it was booked; put on Disputes to review']);

    expect(Transaction::toReview()->sole())->id->toBe($row->id)->review_amount->toBe(1_000)
        ->description->toBe('Part beyond the cap; Provider reports conflicting outcome: failed after it was booked');
});

test('a failed report that finds no row waits on the payment locks, and sees a refund another delivery booked meanwhile', function () {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', ['amount' => 2_500, 'status' => 'failed']))]);

    // A "succeeded" delivery for this Dashboard refund holds the payment lock and commits once the failed one asks for it.
    $booked = false;
    DB::listen(function (QueryExecuted $query) use ($payment, &$booked) {
        if (! $booked && str_contains($query->sql, 'from "transactions" where "id" in') && $query->bindings === [$payment->id]) {
            $booked = true;
            app(RefundTransaction::class)->handle($payment->fresh(), 2_500, RefundMode::RecordOnly, gatewayTxnId: 're_3MoharRefund0001', reason: 'Refunded at the gateway');
        }
    });

    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001'))->assertOk()
        ->assertJson(['result' => 'refund failed at the provider after it was booked; put on Disputes to review']);

    expect($booked)->toBeTrue()->and(Transaction::toReview()->sole())->gateway_txn_id->toBe('re_3MoharRefund0001')->review_amount->toBe(2_500);
});
