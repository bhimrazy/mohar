<?php

use App\Contracts\Billing\CreditLedger;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\PaymentReversed;
use App\Modules\Billing\Actions\CaptureInvoice;
use App\Modules\Billing\Actions\CreateAddFundsInvoice;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\ClientFlag;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Money;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/helpers.php';

/**
 * @param  array<string, mixed>  $payload
 */
function webhook(array $payload, string $secret = 'whsec_test'): TestResponse
{
    $body = (string) json_encode($payload);
    $timestamp = now()->getTimestamp();

    return test()->call('POST', route('billing.webhooks', 'sandbox_card'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SANDBOX_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_SANDBOX_SIGNATURE' => SandboxCardDriver::signature($body, $secret, $timestamp),
    ], $body);
}

beforeEach(function () {
    sandboxGateway();
});

test('a signed payment webhook pays the invoice exactly once', function () {
    Event::fake([InvoicePaid::class]);
    $invoice = issueInvoice(billingClient(), [5_000]);
    $payload = ['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'sbx_ch_1', 'amount' => 5_000, 'currency' => 'USD'];

    webhook($payload)->assertOk()->assertJson(['result' => 'payment recorded']);
    webhook($payload)->assertOk()->assertJson(['result' => 'duplicate']);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->where('gateway_txn_id', 'sbx_ch_1')->count())->toBe(1);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

test('an invalid signature records nothing and is logged', function () {
    $invoice = issueInvoice(billingClient(), [5_000]);

    webhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'sbx_ch_2', 'amount' => 5_000, 'currency' => 'USD'], 'wrong-secret')
        ->assertForbidden();

    expect(Transaction::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(GatewayLog::query()->where('result', 'invalid signature')->exists())->toBeTrue();
});

test('webhooks for inactive gateways, unknown invoices and other currencies are rejected', function () {
    $invoice = issueInvoice(billingClient(), [5_000]);

    webhook(['type' => 'payment.succeeded', 'invoice_id' => 999_999, 'txn_id' => 'a', 'amount' => 1, 'currency' => 'USD'])->assertNotFound();
    webhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'b', 'amount' => 5_000, 'currency' => 'EUR'])->assertUnprocessable();

    Gateway::query()->update(['is_active' => false]);
    webhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'c', 'amount' => 5_000, 'currency' => 'USD'])->assertNotFound();

    expect(Transaction::query()->count())->toBe(0);
});

test('an under-payment webhook is a partial payment', function () {
    $invoice = issueInvoice(billingClient(), [5_000]);

    webhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'p1', 'amount' => 2_000, 'currency' => 'USD'])->assertOk();

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Unpaid)->balance->toBe(3_000);
});

test('a chargeback moves the invoice to collections and reverses the payment', function () {
    Event::fake([PaymentReversed::class]);
    $invoice = issueInvoice(billingClient(), [5_000]);
    webhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'sbx_ch_9', 'amount' => 5_000, 'currency' => 'USD']);

    webhook(['type' => 'charge.dispute', 'invoice_id' => $invoice->id, 'txn_id' => 'sbx_dp_1', 'parent_txn_id' => 'sbx_ch_9', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();
    webhook(['type' => 'charge.dispute', 'invoice_id' => $invoice->id, 'txn_id' => 'sbx_dp_1', 'parent_txn_id' => 'sbx_ch_9', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Collections)->balance->toBe(5_000)
        ->and(Transaction::query()->where('type', 'chargeback')->count())->toBe(1);
    Event::assertDispatchedTimes(PaymentReversed::class, 1);
});

test('a chargeback on a deposit removes the credit even below zero', function () {
    $client = billingClient();
    ClientFlag::query()->create(['client_id' => $client->id, 'first_order_accepted_at' => now()]);
    $invoice = app(CreateAddFundsInvoice::class)->handle($client, 2_000);
    webhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'dep_1', 'amount' => 2_000, 'currency' => 'USD']);
    app(CreditLedger::class)->debit($client->id, Money::of(1_500, 'USD'), 'Spent');

    webhook(['type' => 'charge.dispute', 'invoice_id' => $invoice->id, 'txn_id' => 'dep_dp', 'parent_txn_id' => 'dep_1', 'amount' => 2_000, 'currency' => 'USD'])->assertOk();

    expect(app(CreditLedger::class)->balance($client->id, 'USD')->amount)->toBe(-1_500);
});

test('a refund webhook before its payment is rejected for retry, then recorded once', function () {
    $invoice = issueInvoice(billingClient(), [5_000]);
    $refund = ['type' => 'charge.refunded', 'invoice_id' => $invoice->id, 'txn_id' => 'sbx_re_1', 'parent_txn_id' => 'sbx_ch_5', 'amount' => 1_000, 'currency' => 'USD'];

    webhook($refund)->assertStatus(409);

    webhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'sbx_ch_5', 'amount' => 5_000, 'currency' => 'USD']);
    webhook($refund)->assertOk();
    webhook($refund)->assertOk();

    expect(Transaction::query()->where('type', 'refund')->count())->toBe(1)
        ->and(Transaction::query()->where('gateway_txn_id', 'sbx_ch_5')->value('refundable_remaining'))->toBe(4_000);
});

test('a failed settlement webhook returns a pending invoice to unpaid', function () {
    $invoice = issueInvoice(billingClient(), [5_000]);
    $invoice->update(['status' => InvoiceStatus::PaymentPending]);

    webhook(['type' => 'payment.failed', 'invoice_id' => $invoice->id, 'txn_id' => 'sbx_ch_7', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

test('a converting gateway charges round(balance × rate) and stores both amounts', function () {
    Currency::factory()->create(['code' => 'EUR', 'name' => 'Euro', 'prefix' => '€', 'exchange_rate' => 1, 'is_default' => true]);
    Currency::factory()->create(['code' => 'USD', 'exchange_rate' => 1.1, 'is_default' => false]);
    Gateway::query()->update(['convert_to_currency' => 'USD']);
    $client = Client::factory()->create(['currency' => 'EUR']);
    $invoice = issueInvoice($client, [10_001]);
    $card = PayMethod::factory()->create(['client_id' => $client->id]);

    app(CaptureInvoice::class)->handle($invoice, 'sandbox_card', $card->token, $card);

    $transaction = Transaction::query()->where('invoice_id', $invoice->id)->firstOrFail();
    expect($transaction)->amount_in->toBe(10_001)->currency->toBe('EUR')
        ->gateway_amount->toBe(11_001)->gateway_currency->toBe('USD')->exchange_rate->toBe('1.100000')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('a pending capture settles through the webhook', function () {
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    $card = PayMethod::factory()->withCard('4000000000000077')->create(['client_id' => $client->id]);

    $result = app(CaptureInvoice::class)->handle($invoice, 'sandbox_card', $card->token, $card);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PaymentPending);

    webhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => $result->txnId, 'amount' => 5_000, 'currency' => 'USD'])->assertOk();

    expect(Invoice::query()->find($invoice->id)->status)->toBe(InvoiceStatus::Paid);
});

test('a replayed callback older than five minutes is rejected', function () {
    $invoice = issueInvoice(billingClient(), [5_000]);
    $body = (string) json_encode(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'sbx_old', 'amount' => 5_000, 'currency' => 'USD']);
    $sent = now()->subMinutes(6)->getTimestamp();
    $headers = fn (int $timestamp, int $signedAt) => ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SANDBOX_TIMESTAMP' => (string) $timestamp, 'HTTP_X_SANDBOX_SIGNATURE' => SandboxCardDriver::signature($body, 'whsec_test', $signedAt)];

    $this->call('POST', route('billing.webhooks', 'sandbox_card'), [], [], [], $headers($sent, $sent), $body)->assertStatus(400)->assertJson(['result' => 'stale callback']);
    // Re-dating the captured request breaks the signature.
    $this->call('POST', route('billing.webhooks', 'sandbox_card'), [], [], [], $headers(now()->getTimestamp(), $sent), $body)->assertForbidden();

    expect(Transaction::query()->count())->toBe(0)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});
