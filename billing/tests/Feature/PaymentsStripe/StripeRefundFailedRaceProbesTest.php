<?php

use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

// Re-review probes for #505 (fix round): pin the parts of the pending-then-booked race handling no PR test fails without.

beforeEach(function () {
    Http::preventStrayRequests();
    stripeGateway();
});

/** A $25.00 Stripe payment and a 1,000 refund left pending; Stripe answers each refund fetch with $state and $amount. */
function raceProbePendingRefund(string &$state, int &$amount, ?Transaction &$row): Transaction
{
    [, $invoice, $attempt] = startStripe(2_500);
    Http::fake([STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'*' => Http::response(stripePaidSession($attempt))]);
    test()->get(route('billing.gateways.return', [StripeDriver::KEY, 'ref' => $attempt->reference]));
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    $payment = Transaction::query()->where('gateway', StripeDriver::KEY)->where('type', TransactionType::Payment)->sole();

    $answer = function () use (&$state, &$amount, &$row) {
        return Http::response(stripeFixture('refund', ['status' => $state, 'amount' => $amount, 'metadata' => $row !== null ? ['mohar_origin' => 'admin', 'mohar_refund' => (string) $row->id] : []]));
    };
    Http::fake([STRIPE_API.'refunds' => $answer, STRIPE_API.'refunds/re_3MoharRefund0001*' => $answer]);
    $row = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);
    expect($row->fresh()->status)->toBe(TransactionStatus::Pending);

    return $payment;
}

test('probe: a pending credit refund reported failed and then settled by a racing delivery goes on Disputes to review', function () {
    [$state, $amount, $row] = ['pending', 1_000, null];
    raceProbePendingRefund($state, $amount, $row);
    $row->forceFill(['purpose' => Transaction::PURPOSE_CREDIT_REFUND])->save();

    $state = 'failed';
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001'))->assertOk()
        ->assertJson(['result' => 'refund failed at the provider; Check refund releases it']);
    expect(Transaction::toReview()->count())->toBe(0);

    $state = 'succeeded';
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0031'))->assertOk();

    expect(Transaction::toReview()->sole())->id->toBe($row->id)->status->toBe(TransactionStatus::Succeeded)
        ->review_amount->toBe(1_000)->reviewed_at->toBeNull();
});

test('probe: the provider amount from a failed report on a pending row is kept for the review when the row is booked', function () {
    [$state, $amount, $row] = ['pending', 1_000, null];
    raceProbePendingRefund($state, $amount, $row);

    [$state, $amount] = ['failed', 2_500];
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001'))->assertOk()
        ->assertJson(['result' => 'refund failed at the provider; Check refund releases it']);

    [$state, $amount] = ['succeeded', 1_000];
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0032'))->assertOk()
        ->assertJson(['result' => 'refund recorded']);

    expect(Transaction::toReview()->sole())->id->toBe($row->id)->review_amount->toBe(2_500);
});

test('probe: settling a marked row again after staff reviewed it does not reopen it', function () {
    [$state, $amount, $row] = ['pending', 1_000, null];
    raceProbePendingRefund($state, $amount, $row);

    $state = 'failed';
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001'))->assertOk();
    $state = 'succeeded';
    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0033'))->assertOk();
    expect(Transaction::toReview()->sole()->id)->toBe($row->id);

    $row->fresh()->forceFill(['reviewed_at' => now()])->save();
    $again = app(RefundTransaction::class)->settle($row->fresh(), 're_3MoharRefund0001');

    expect($again->id)->toBe($row->id)->and(Transaction::toReview()->count())->toBe(0)
        ->and($row->fresh()->reviewed_at)->not->toBeNull();
});
