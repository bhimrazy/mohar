<?php

use App\Contracts\Billing\CreditLedger;
use App\Modules\Billing\Actions\CreateAddFundsInvoice;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Models\ClientFlag;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/WebhookHelpers.php';

/**
 * Review probes for PR #229 (#223): a deposit's credit is taken back exactly once across refunds and disputes, in
 * either order, so the client's credit always equals what was paid in minus what went back out.
 */
beforeEach(function () {
    sandboxGateway();
});

function pr229Deposit(Client $client, string $charge, int $paid = 5_000): Invoice
{
    ClientFlag::query()->create(['client_id' => $client->id, 'first_order_accepted_at' => now()]);
    $deposit = app(CreateAddFundsInvoice::class)->handle($client, 5_000);
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $deposit->id, 'txn_id' => $charge, 'amount' => $paid, 'currency' => 'USD'])->assertOk();

    return $deposit;
}

function pr229Credit(Client $client): int
{
    return app(CreditLedger::class)->balance($client->id, 'USD')->amount;
}

test('probe D1: refund 2,000 then dispute 3,000 of a 5,000 deposit ends at 0', function () {
    $client = billingClient();
    pr229Deposit($client, 'ch_p1');
    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_p1', 'parent_txn_id' => 'ch_p1', 'amount' => 2_000, 'currency' => 'USD'])->assertOk();
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_p1', 'parent_txn_id' => 'ch_p1', 'amount' => 3_000, 'currency' => 'USD'])->assertOk();

    expect(pr229Credit($client))->toBe(0);
});

test('probe D2: dispute 3,000 then refund 2,000 of a 5,000 deposit ends at 0', function () {
    $client = billingClient();
    pr229Deposit($client, 'ch_p2');
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_p2', 'parent_txn_id' => 'ch_p2', 'amount' => 3_000, 'currency' => 'USD'])->assertOk();
    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_p2', 'parent_txn_id' => 'ch_p2', 'amount' => 2_000, 'currency' => 'USD'])->assertOk();

    expect(pr229Credit($client))->toBe(0);
});

test('probe D3: 6,000 on a 5,000 deposit, dispute 6,000 then a replay ends at 0', function () {
    $client = billingClient();
    pr229Deposit($client, 'ch_p3', 6_000);
    $dispute = ['type' => 'charge.dispute', 'txn_id' => 'dp_p3', 'parent_txn_id' => 'ch_p3', 'amount' => 6_000, 'currency' => 'USD'];
    sandboxWebhook($dispute)->assertOk();
    sandboxWebhook($dispute)->assertOk();

    expect(pr229Credit($client))->toBe(0);
});

test('probe D4: dispute 5,000, won, then refund 5,000 of a deposit ends at 0', function () {
    $client = billingClient();
    pr229Deposit($client, 'ch_p4');
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_p4', 'parent_txn_id' => 'ch_p4', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();
    expect(pr229Credit($client))->toBe(0);
    sandboxWebhook(['type' => 'charge.dispute.won', 'txn_id' => 'dp_p4', 'parent_txn_id' => 'ch_p4', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();
    expect(pr229Credit($client))->toBe(5_000);
    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_p4', 'parent_txn_id' => 'ch_p4', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();

    expect(pr229Credit($client))->toBe(0);
});

test('probe D5: a staff refund to credit of a deposit, then a provider dispute of the charge, ends at 0', function () {
    $client = billingClient();
    $deposit = pr229Deposit($client, 'ch_p5');
    $payment = Transaction::query()->where('invoice_id', $deposit->id)->where('type', 'payment')->sole();
    app(RefundTransaction::class)->handle($payment, 5_000, RefundMode::ToCredit);
    expect(pr229Credit($client))->toBe(5_000);

    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_p5', 'parent_txn_id' => 'ch_p5', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();

    expect(pr229Credit($client))->toBe(0);
});

test('probe D6: two staff refunds to credit of 2,500 each keep the deposit at 5,000 credit', function () {
    $client = billingClient();
    $deposit = pr229Deposit($client, 'ch_p6');
    $payment = Transaction::query()->where('invoice_id', $deposit->id)->where('type', 'payment')->sole();
    app(RefundTransaction::class)->handle($payment, 2_500, RefundMode::ToCredit);
    app(RefundTransaction::class)->handle($payment->fresh(), 2_500, RefundMode::ToCredit);

    expect(pr229Credit($client))->toBe(5_000);
});
