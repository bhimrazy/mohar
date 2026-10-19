<?php

use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
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
        ->push(stripeFixture('refund', ['id' => 're_3MoharRefund0002', 'amount' => 1_500, 'status' => 'pending']))]);

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

test('a refund webhook arriving while the refund action is still recording it is retried, then deduplicated', function () {
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

    $during->assertStatus(409)->assertJson(['result' => 'refund still being recorded']);
    postStripeWebhook(stripeEvent('refund.created', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0009'))->assertOk();
    expect(Transaction::query()->where('type', TransactionType::Refund)->sole())->status->toBe(TransactionStatus::Succeeded)->amount_out->toBe(2_500);
});

test('a staff refund that timed out but went through at Stripe is booked from its webhook, once', function () {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds' => fn () => throw new ConnectionException('timed out')]);

    expect(fn () => app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway))->toThrow(BillingException::class, 'check the Stripe Dashboard before retrying');
    $failed = Transaction::query()->where('type', TransactionType::Refund)->sole();
    expect($failed->status)->toBe(TransactionStatus::Failed)->and($payment->fresh()->refundable_remaining)->toBe(2_500);

    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', ['metadata' => ['mohar_origin' => 'admin', 'mohar_refund' => (string) $failed->id]]))]);
    postStripeWebhook(stripeEvent('refund.created', 're_3MoharRefund0001'))->assertOk()->assertJson(['result' => 'refund recorded']);
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0010'))->assertOk()->assertJson(['result' => 'duplicate']);

    expect(Transaction::query()->where('type', TransactionType::Refund)->where('status', TransactionStatus::Succeeded)->sole())->amount_out->toBe(1_000)->gateway_txn_id->toBe('re_3MoharRefund0001')
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500);
});

test('a refund from the admin that failed at Stripe books nothing and is logged for staff', function () {
    paidStripePayment();
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', ['status' => 'failed', 'metadata' => ['mohar_origin' => 'admin', 'mohar_refund' => '99']]))]);

    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001'))->assertOk()->assertJson(['result' => 'ignored event type']);

    expect(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(0)
        ->and(GatewayLog::query()->latest('id')->first()->payload['type'])->toContain('FAILED at Stripe');
});

test('a refund Stripe refuses or never answers leaves the payment refundable', function (Closure $response, string $message) {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds' => $response]);

    expect(fn () => app(RefundTransaction::class)->handle($payment, 2_500, RefundMode::Gateway))->toThrow(BillingException::class, $message)
        ->and($payment->fresh()->refundable_remaining)->toBe(2_500)
        ->and(Transaction::query()->where('type', TransactionType::Refund)->sole()->status)->toBe(TransactionStatus::Failed)
        ->and($payment->invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
})->with([
    'refused' => [fn () => Http::response(stripeFixture('error_already_refunded'), 400), 'has already been refunded'],
    'no answer' => [fn () => throw new ConnectionException('timed out'), 'check the Stripe Dashboard before retrying'],
    'failed' => [fn () => Http::response(stripeFixture('refund', ['status' => 'failed'])), 'Stripe refund failed'],
]);
