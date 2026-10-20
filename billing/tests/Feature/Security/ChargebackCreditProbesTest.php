<?php

use App\Contracts\Billing\CreditLedger;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Models\Transaction;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Billing/helpers.php';

/**
 * Periodic security review 2026-10-20, H1: a gateway reversal (dispute or refund) of a payment that went to account
 * credit must take that credit back, or flag the movement for staff. #152 does this for refunds of flagged hosted
 * attempts only; a chargeback never touches credit except on add-funds invoices, and a provider refund of a plain
 * overpayment ends as a log line. Each test states the safe behaviour, so it fails until the fix lands.
 *
 * @param  array<string, mixed>  $payload
 */
function reversalProbeWebhook(array $payload): TestResponse
{
    $body = (string) json_encode($payload);
    $timestamp = now()->getTimestamp();

    return test()->call('POST', route('billing.webhooks', 'sandbox_card'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SANDBOX_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_SANDBOX_SIGNATURE' => SandboxCardDriver::signature($body, 'whsec_test', $timestamp),
    ], $body);
}

beforeEach(function () {
    sandboxGateway();
});

test('probe H1a: a dispute of a payment that went wholly to credit takes the credit back', function () {
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    reversalProbeWebhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'h1_ch_1', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();
    // The same invoice paid a second time (second tab, late session): all of it becomes account credit.
    reversalProbeWebhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'h1_ch_2', 'amount' => 5_000, 'currency' => 'USD'])
        ->assertOk()->assertJson(['result' => 'payment recorded on closed invoice']);
    expect(app(CreditLedger::class)->balance($client->id, 'USD')->amount)->toBe(5_000);

    // The client disputes the second charge: the bank returns the 5,000.
    reversalProbeWebhook(['type' => 'charge.dispute', 'invoice_id' => $invoice->id, 'txn_id' => 'h1_dp_2', 'parent_txn_id' => 'h1_ch_2', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();

    $chargeback = Transaction::query()->where('type', 'chargeback')->where('gateway_txn_id', 'h1_dp_2')->firstOrFail();

    // Safe: the credit the disputed money created is gone (or the chargeback carries the full amount against it).
    expect(app(CreditLedger::class)->balance($client->id, 'USD')->amount)->toBe(0)
        ->and($chargeback->amount_out)->toBe(5_000);
});

test('probe H1b: a dispute of an overpayment takes the overpaid credit back', function () {
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    reversalProbeWebhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'h1_ch_3', 'amount' => 8_000, 'currency' => 'USD'])->assertOk();
    expect(app(CreditLedger::class)->balance($client->id, 'USD')->amount)->toBe(3_000);

    reversalProbeWebhook(['type' => 'charge.dispute', 'invoice_id' => $invoice->id, 'txn_id' => 'h1_dp_3', 'parent_txn_id' => 'h1_ch_3', 'amount' => 8_000, 'currency' => 'USD'])->assertOk();

    expect(app(CreditLedger::class)->balance($client->id, 'USD')->amount)->toBe(0);
});

test('probe H1c: a provider refund of an overpayment takes the overpaid credit back or flags it for staff', function () {
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    reversalProbeWebhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'h1_ch_4', 'amount' => 8_000, 'currency' => 'USD'])->assertOk();

    $response = reversalProbeWebhook(['type' => 'charge.refunded', 'invoice_id' => $invoice->id, 'txn_id' => 'h1_re_4', 'parent_txn_id' => 'h1_ch_4', 'amount' => 8_000, 'currency' => 'USD'])->assertOk();

    // Today: "refund needs staff review" in the gateway log only, and the 3,000 credit stays spendable.
    expect($response->json('result'))->not->toBe('refund needs staff review')
        ->and(app(CreditLedger::class)->balance($client->id, 'USD')->amount)->toBe(0);
});
