<?php

use App\Events\Billing\PaymentReversed;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Transaction;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

/**
 * Review probes for PR #229 (#212): a chargeback is booked once whichever dispute event arrives first; an inquiry
 * that escalates leaves no open inquiry to-do.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    stripeGateway();
});

function probeStripePaid(): void
{
    [, $invoice, $attempt] = startStripe();
    $metadata = ['mohar_reference' => $attempt->reference, 'mohar_invoice_id' => (string) $invoice->id];
    Http::fake([
        STRIPE_API.'payment_intents/'.STRIPE_INTENT => Http::response(stripeFixture('payment_intent_succeeded', ['metadata' => $metadata])),
        STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'*' => Http::response(stripePaidSession($attempt, ['payment_intent' => []])),
    ]);
    postStripeWebhook(stripeEvent('payment_intent.succeeded', STRIPE_INTENT))->assertOk();
}

test('probe S1: created already carrying the withdrawal, then funds_withdrawn, books one chargeback', function (array $order) {
    Event::fake([PaymentReversed::class]);
    probeStripePaid();
    Http::fake([STRIPE_API.'disputes/du_1MoharDispute0001*' => Http::response(stripeFixture('dispute'))]);

    foreach ($order as $index => $type) {
        postStripeWebhook(stripeEvent($type, 'du_1MoharDispute0001', eventId: 'evt_1MoharEvent009'.$index))->assertOk();
    }

    expect(Transaction::query()->where('type', TransactionType::Chargeback)->count())->toBe(1)
        ->and(Transaction::query()->where('type', TransactionType::DisputeInquiry)->exists())->toBeFalse();
    Event::assertDispatchedTimes(PaymentReversed::class, 1);
})->with([
    'created first' => [['charge.dispute.created', 'charge.dispute.funds_withdrawn']],
    'funds_withdrawn first' => [['charge.dispute.funds_withdrawn', 'charge.dispute.created']],
]);
