<?php

use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\PaymentsRazorpay\Drivers\RazorpayDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| Review probes from PR #493, adopted: A (a `pending` refund is not booked as made), C (a redelivered link event is
| looked up again) and I2 (payment link host check).
*/

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    FakeRazorpay::install();
    razorpayGateway();
});

/** A paid Rs 1,130.00 invoice, and a full refund that Razorpay accepts as `pending`. */
function pendingRazorpayRefund(): Transaction
{
    [, $invoice, $attempt] = startRazorpay(113_000);
    razorpayPaid($attempt);
    test()->get(razorpayReturn($attempt));
    $payment = Transaction::query()->where('gateway', RazorpayDriver::KEY)->where('type', TransactionType::Payment)->sole();
    FakeRazorpay::$refund = fn (Request $request) => FakeRazorpay::$refunds['rfnd_MoharPending01'] = rzpRefund('rfnd_MoharPending01', $request['amount'], ['status' => 'pending', 'notes' => $request['notes']]);

    $row = app(RefundTransaction::class)->handle($payment, 113_000, RefundMode::Gateway);

    expect($row->status)->toBe(TransactionStatus::Pending)->and($row->awaitsProviderConfirmation())->toBeTrue()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($payment->fresh()->refundable_remaining)->toBe(0);

    return $row;
}

test('probe A: a pending refund that then fails at Razorpay stays pending, and Check refund releases it', function () {
    $row = pendingRazorpayRefund();
    FakeRazorpay::$refunds['rfnd_MoharPending01']['status'] = 'failed';

    postRazorpayWebhook(rzpEvent('refund.failed', ['refund' => ['id' => 'rfnd_MoharPending01']]))->assertOk()->assertJson(['result' => 'ignored event type']);
    expect($row->fresh()->status)->toBe(TransactionStatus::Pending);

    $this->travel(16)->minutes();
    expect(app(RefundTransaction::class)->check($row->fresh())->status)->toBe(TransactionStatus::Failed)
        ->and($row->parent->fresh()->refundable_remaining)->toBe(113_000)
        ->and($row->invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('probe A: a pending refund is booked once refund.processed arrives, and Check refund agrees', function () {
    $row = pendingRazorpayRefund();
    expect(app(RefundTransaction::class)->check($row)->status)->toBe(TransactionStatus::Pending);

    FakeRazorpay::$refunds['rfnd_MoharPending01']['status'] = 'processed';
    postRazorpayWebhook(rzpEvent('refund.processed', ['refund' => ['id' => 'rfnd_MoharPending01']]))->assertOk()->assertJson(['result' => 'refund recorded']);

    expect($row->fresh())->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('rfnd_MoharPending01')
        ->and($row->invoice->fresh()->status)->toBe(InvoiceStatus::Refunded)
        ->and(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(1);
});

test('probe C: a link event acknowledged while Razorpay was unreachable settles on redelivery', function () {
    [, $invoice, $attempt] = startRazorpay();
    FakeRazorpay::$links[RZP_LINK] = fn () => throw new ConnectionException('timed out');
    $event = rzpEvent('payment_link.paid', ['payment_link' => rzpLink($attempt->reference, $attempt->amount, ['status' => 'paid'])]);

    postRazorpayWebhook($event)->assertOk()->assertJson(['result' => 'attempt pending']);
    razorpayPaid($attempt);
    postRazorpayWebhook($event)->assertOk()->assertJson(['result' => 'payment recorded']);
    postRazorpayWebhook($event)->assertOk()->assertJson(['result' => 'duplicate']);

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->where('gateway', RazorpayDriver::KEY)->count())->toBe(1);
});

test('probe I2: a payment link url off rzp.io is never handed to the customer', function (string $url) {
    Currency::factory()->inr()->create();
    [, $client] = actingAsClient(Client::factory()->create(['currency' => 'INR', 'country' => 'IN']));
    $invoice = issueInvoice($client, [113_000]);
    Http::fake(['*payment_links' => fn ($request) => Http::response(rzpLink($request['reference_id'], $request['amount'], ['short_url' => $url, 'notes' => $request['notes']]))]);

    $response = $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => RazorpayDriver::KEY]);

    expect($response->headers->get('Location'))->not->toBe($url);
})->with(['https://rzp.io.evil.example/i/x', 'http://rzp.io/i/x', 'https://rzp.io:8443/i/x', 'https://user@rzp.io/i/x', 'https://evilrzp.io/i/x', 'https://rzp.io\\@evil.example/', 'javascript:alert(1)//rzp.io']);
