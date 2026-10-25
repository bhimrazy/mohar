<?php

use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsRazorpay\Drivers\RazorpayDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    FakeRazorpay::install();
    razorpayGateway();
});

test('a webhook without a valid signature over the raw body is refused before anything is read or fetched', function (Closure $post) {
    [, $invoice, $attempt] = startRazorpay();
    razorpayPaid($attempt);
    $sent = Http::recorded()->count();

    $post(rzpEvent('payment_link.paid', ['payment_link' => FakeRazorpay::$links[RZP_LINK]]))->assertForbidden();

    expect(Http::recorded()->count())->toBe($sent)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(GatewayLog::query()->where('result', 'invalid signature')->count())->toBe(1);
})->with([
    'wrong secret' => [fn (array $event) => postRazorpayWebhook($event, secret: 'NotTheWebhookSecret0000000000000')],
    'signed with the key secret' => [fn (array $event) => postRazorpayWebhook($event, secret: RZP_KEY_SECRET)],
    'no signature' => [fn (array $event) => postRazorpayWebhook($event, signature: '')],
    'body changed after signing' => [fn (array $event) => postRazorpayWebhook(json_encode($event).' ', signature: hash_hmac('sha256', (string) json_encode($event), RZP_WEBHOOK_SECRET))],
]);

test('payment_link.paid settles by lookup, never from the body, and a redelivered event id is acted on once', function () {
    [, $invoice, $attempt] = startRazorpay();
    // The body claims a payment the lookup doesn't confirm: the link is still open at Razorpay.
    $event = rzpEvent('payment_link.paid', ['payment_link' => [...FakeRazorpay::$links[RZP_LINK], 'status' => 'paid', 'amount_paid' => 113_000]]);

    postRazorpayWebhook($event)->assertOk()->assertJson(['result' => 'attempt pending']);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);

    razorpayPaid($attempt);
    $calls = Http::recorded()->count();
    postRazorpayWebhook($event)->assertOk()->assertJson(['result' => 'ignored event type']);
    expect(Http::recorded()->count())->toBe($calls)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);

    postRazorpayWebhook($event, eventId: 'MoharEvent0002')->assertOk()->assertJson(['result' => 'payment recorded']);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)->and($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded);
});

test('events that are not ours or not used are acknowledged without a provider call', function (array $event) {
    startRazorpay();
    $calls = Http::recorded()->count();

    postRazorpayWebhook($event)->assertOk()->assertJson(['result' => 'ignored event type']);

    expect(Http::recorded()->count())->toBe($calls)->and(Transaction::query()->where('gateway', RazorpayDriver::KEY)->count())->toBe(0);
})->with([
    'another site\'s link' => [rzpEvent('payment_link.paid', ['payment_link' => rzpLink('other-site-ref', 5_000, ['notes' => []])])],
    'payment.captured' => [rzpEvent('payment.captured', ['payment' => rzpPayment(113_000, 'x')])],
]);

test('a refund made in the Razorpay dashboard is fetched and booked once, whatever the body says', function () {
    [, $invoice, $attempt] = startRazorpay();
    razorpayPaid($attempt);
    $this->get(razorpayReturn($attempt));
    FakeRazorpay::$refunds['rfnd_MoharDashboard1'] = rzpRefund('rfnd_MoharDashboard1', 13_000);
    $event = rzpEvent('refund.processed', ['refund' => rzpRefund('rfnd_MoharDashboard1', 113_000), 'payment' => FakeRazorpay::$payments[RZP_PAYMENT]]);

    postRazorpayWebhook($event)->assertOk()->assertJson(['result' => 'refund recorded']);
    postRazorpayWebhook($event, eventId: 'MoharEvent0002')->assertOk()->assertJson(['result' => 'duplicate']);

    expect(Transaction::query()->where('type', TransactionType::Refund)->sole())->gateway_txn_id->toBe('rfnd_MoharDashboard1')->amount_out->toBe(13_000);
    Http::assertSent(fn (Request $request) => $request->url() === RZP_API.'refunds/rfnd_MoharDashboard1');
});

test('refund events that must not book are acknowledged, and a transient fetch failure asks Razorpay to retry', function (Closure $setup, int $status, string $result) {
    [, , $attempt] = startRazorpay();
    razorpayPaid($attempt);
    $this->get(razorpayReturn($attempt));
    FakeRazorpay::$refunds['rfnd_MoharDashboard1'] = rzpRefund('rfnd_MoharDashboard1', 13_000);
    $setup();

    postRazorpayWebhook(rzpEvent('refund.processed', ['refund' => ['id' => 'rfnd_MoharDashboard1']]))->assertStatus($status)->assertJson(['result' => $result]);

    expect(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(0);
})->with([
    'still pending' => [fn () => FakeRazorpay::$refunds['rfnd_MoharDashboard1']['status'] = 'pending', 200, 'ignored event type'],
    'failed' => [fn () => FakeRazorpay::$refunds['rfnd_MoharDashboard1']['status'] = 'failed', 200, 'ignored event type'],
    'payment not made here' => [fn () => FakeRazorpay::$payments[RZP_PAYMENT]['notes'] = [], 200, 'ignored event type'],
    'Razorpay outage' => [fn () => FakeRazorpay::$refunds['rfnd_MoharDashboard1'] = 503, 422, 'Razorpay fetch failed: HTTP 503; code: BAD_REQUEST_ERROR; description: Request failed; reason: NA'],
]);
