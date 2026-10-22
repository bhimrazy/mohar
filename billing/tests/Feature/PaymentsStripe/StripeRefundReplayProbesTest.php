<?php

/*
 * Probes from the final re-check of #336, adopted: replay answers that must not release, and the 72-hour gate.
 * Each test asserts the safe behaviour.
 */

use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

function rr336fPayment(): Transaction
{
    [, $invoice, $attempt] = startStripe(2_500);
    Http::fake([STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'*' => Http::response(stripePaidSession($attempt))]);
    test()->get(route('billing.gateways.return', [StripeDriver::KEY, 'ref' => $attempt->reference]));
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    return Transaction::query()->where('gateway', StripeDriver::KEY)->where('type', TransactionType::Payment)->sole();
}

beforeEach(function () {
    Http::preventStrayRequests();
    stripeGateway();
});

// Stripe answers a replay with a fresh execution whenever it has no record of the key in the account the
// current key belongs to (another account, another mode). These answers are about that account, or about refunds
// already on the charge, never a definite "our refund does not exist".
test('A1: a replay answer that the charge or PaymentIntent is missing, already refunded or over-refunded must not release', function (int $code, array $error) {
    $payment = rr336fPayment();
    Http::fake([STRIPE_API.'refunds' => Http::sequence()->pushFailedConnection('timed out')->push(['error' => $error], $code)]);
    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);

    $row = app(RefundTransaction::class)->check($pending);

    expect($row->status)->toBe(TransactionStatus::Pending)
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500);
})->with([
    'resource_missing 400 (key now for another account)' => [400, ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'param' => 'payment_intent', 'message' => 'No such payment_intent']],
    'resource_missing 404' => [404, ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such payment_intent']],
    'charge_already_refunded' => [400, ['type' => 'invalid_request_error', 'code' => 'charge_already_refunded', 'message' => 'Charge has already been refunded.']],
    'amount_too_large' => [400, ['type' => 'invalid_request_error', 'code' => 'amount_too_large', 'param' => 'amount', 'message' => 'Refund amount is greater than unrefunded amount on charge']],
]);

test('A2: a 2xx replay with no refund in the body is not a definite no', function () {
    $payment = rr336fPayment();
    Http::fake([STRIPE_API.'refunds' => Http::sequence()->pushFailedConnection('timed out')->push(['object' => 'unexpected'], 200)]);
    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);

    expect(app(RefundTransaction::class)->check($pending)->status)->toBe(TransactionStatus::Pending)
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500);
});

test('A3 (control): a cached failed refund on replay is a definite no and releases', function () {
    $payment = rr336fPayment();
    Http::fake([STRIPE_API.'refunds' => Http::sequence()->pushFailedConnection('timed out')->push(stripeFixture('refund', ['id' => 're_3MoharA3', 'amount' => 1_000, 'status' => 'failed']))]);
    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);

    expect(app(RefundTransaction::class)->check($pending)->status)->toBe(TransactionStatus::Failed)
        ->and($payment->fresh()->refundable_remaining)->toBe(2_500);
});

test('A4: the 72-hour hand release needs an unknown answer from a check made after the 72 hours, not a stale one', function () {
    $payment = rr336fPayment();
    // One Check at hour 1 answers 409 (unknown) and nobody checks again.
    Http::fake([STRIPE_API.'refunds' => Http::sequence()->pushFailedConnection('timed out')->push(['error' => ['type' => 'idempotency_error', 'message' => 'in use']], 409)]);
    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);
    $this->travel(1)->hours();
    expect(app(RefundTransaction::class)->check($pending)->status)->toBe(TransactionStatus::Pending);
    $this->travel(72)->hours();

    // A Check now would list refunds and give a definite answer; staff release by hand without it.
    expect(fn () => app(RefundTransaction::class)->resolve($pending->fresh(), false))->toThrow(BillingException::class);
});

test('A5 (control): the stuck gate is re-read under lock: a Check that settles first makes a stale resolve throw', function () {
    $payment = rr336fPayment();
    Http::fake([STRIPE_API.'refunds' => Http::sequence()->pushFailedConnection('timed out')->push(['error' => ['message' => 'down']], 500)]);
    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);
    app(RefundTransaction::class)->check($pending);
    $pending->forceFill(['created_at' => now()->subHours(80)])->save();
    $stale = $pending->fresh();
    expect($stale->stuckAtLookup())->toBeTrue();

    // Another tab: the webhook-equivalent settle books it.
    app(RefundTransaction::class)->resolve($pending->fresh(), true, 're_dash_1');

    expect(fn () => app(RefundTransaction::class)->resolve($stale, false))->toThrow(BillingException::class)
        ->and($pending->fresh()->status)->toBe(TransactionStatus::Succeeded)
        ->and($payment->fresh()->refundable_remaining)->toBe(1_500);
});
