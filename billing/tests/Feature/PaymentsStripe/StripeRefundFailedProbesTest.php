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

// Review-board probes for #505 (#498): pin the parts of failedAfterBooking() no PR test fails without.

beforeEach(function () {
    Http::preventStrayRequests();
    stripeGateway();
});

function probeBookedStripeRefund(): array
{
    [, $invoice, $attempt] = startStripe(2_500);
    Http::fake([STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'*' => Http::response(stripePaidSession($attempt))]);
    test()->get(route('billing.gateways.return', [StripeDriver::KEY, 'ref' => $attempt->reference]));
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    $payment = Transaction::query()->where('gateway', StripeDriver::KEY)->where('type', TransactionType::Payment)->sole();

    Http::fake([STRIPE_API.'refunds' => Http::response(stripeFixture('refund', ['amount' => 1_000]))]);
    $row = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway);
    expect($row->fresh())->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('re_3MoharRefund0001');

    return [$payment, $row];
}

test('probe: a booked refund staff already reviewed for another reason is reopened when Stripe reports it failed', function () {
    [, $row] = probeBookedStripeRefund();
    $row->forceFill(['review_amount' => 300, 'reviewed_at' => now()->subDay()])->save();
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', ['amount' => 1_000, 'status' => 'failed', 'metadata' => ['mohar_origin' => 'admin', 'mohar_refund' => (string) $row->id]]))]);

    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001'))->assertOk()
        ->assertJson(['result' => 'refund failed at the provider after it was booked; put on Disputes to review']);

    expect(Transaction::toReview()->sole())->id->toBe($row->id)->review_amount->toBe(1_000)->reviewed_at->toBeNull();
});

test('probe: the review amount is the provider amount when it is larger than the booked row', function () {
    [, $row] = probeBookedStripeRefund();
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', ['amount' => 2_500, 'status' => 'failed']))]);

    postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001'))->assertOk();

    expect(Transaction::toReview()->sole())->id->toBe($row->id)->review_amount->toBe(2_500);
});

test('probe: a forged or stale refund.updated failed event changes nothing', function () {
    [, $row] = probeBookedStripeRefund();
    Http::fake([STRIPE_API.'refunds/re_3MoharRefund0001*' => Http::response(stripeFixture('refund', ['amount' => 1_000, 'status' => 'failed']))]);

    expect(postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001'), secret: 'whsec_not_ours')->status())->toBe(403);
    expect(postStripeWebhook(stripeEvent('refund.updated', 're_3MoharRefund0001', eventId: 'evt_1MoharEvent0099'), timestamp: time() - 3_600)->status())->toBeGreaterThanOrEqual(400);

    expect(Transaction::toReview()->count())->toBe(0)->and($row->fresh()->reviewed_at)->toBeNull()
        ->and($row->fresh()->raw[Transaction::REFUND_STATE][Transaction::PROVIDER_FAILED_AT] ?? null)->toBeNull();
});
