<?php

use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
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

test('staff refund part of a Stripe payment and then the rest, in Stripe units with a fresh idempotency key each', function () {
    $payment = paidStripePayment();
    Http::fake([STRIPE_API.'refunds' => Http::sequence()
        ->push(stripeFixture('refund', ['id' => 're_3MoharRefund0001', 'amount' => 1_000, 'metadata' => ['mohar_origin' => 'admin']]))
        ->push(stripeFixture('refund', ['id' => 're_3MoharRefund0002', 'amount' => 1_500, 'status' => 'pending', 'metadata' => ['mohar_origin' => 'admin']]))]);

    app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);
    expect($payment->invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    app(RefundTransaction::class)->handle($payment->fresh(), 1_500, RefundMode::Gateway);

    $refunds = Http::recorded(fn (Request $request) => $request->method() === 'POST' && $request->url() === STRIPE_API.'refunds')->map(fn (array $pair) => $pair[0]);
    expect($refunds)->toHaveCount(2)
        ->and($refunds->map(fn (Request $request) => [$request['payment_intent'], $request['amount'], $request['metadata']])->all())
        ->toBe([[STRIPE_INTENT, 1_000, ['mohar_origin' => 'admin']], [STRIPE_INTENT, 1_500, ['mohar_origin' => 'admin']]])
        ->and($refunds->map(fn (Request $request) => $request->header('Idempotency-Key')[0])->unique())->toHaveCount(2)
        ->and(Transaction::query()->where('type', TransactionType::Refund)->orderBy('id')->pluck('gateway_txn_id')->all())->toBe(['re_3MoharRefund0001', 're_3MoharRefund0002'])
        ->and($payment->invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);

    // The webhooks for these refunds don't book them a second time.
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', ['metadata' => ['mohar_origin' => 'admin']]))]);
    postStripeWebhook(stripeEvent('refund.created', 're_3MoharRefund0001'))->assertOk()->assertJson(['result' => 'ignored event type']);
    expect(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(2);
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
