<?php

use App\Contracts\Billing\CreditLedger;
use App\Modules\Billing\Actions\PaymentBatches;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Support\Dashboard\Dashboard;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Billing/helpers.php';
require_once __DIR__.'/../Billing/WebhookHelpers.php';

/**
 * Review board probes for PR #181 (#172). "FAILS at ee927c1e" marks a finding; the others pin verified behaviour.
 */
beforeEach(function () {
    sandboxGateway();
});

function probeBalance(int $clientId, string $currency = 'USD'): int
{
    return app(CreditLedger::class)->balance($clientId, $currency)->amount;
}

// B1 — FAILS at ee927c1e: credit from a staff "refund to credit" of the payment is not in the cap.
test('B1: a dispute after the payment was refunded to account credit takes that credit back', function () {
    $client = billingClient();
    $payment = payInvoice(issueInvoice($client, [5_000]), 5_000, 'ch_b1', 'sandbox_card');
    app(RefundTransaction::class)->handle($payment, 5_000, RefundMode::ToCredit);
    expect(probeBalance($client->id))->toBe(5_000);

    // The client then disputes the card charge: the bank returns the 5,000 they already hold as credit.
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_b1', 'parent_txn_id' => 'ch_b1', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();

    expect(probeBalance($client->id))->toBe(0);
});

// M1 — FAILS at ee927c1e: the remainder the handler cannot place is a log line only, no staff to-do.
// Adopted with a different setup: with B1 fixed, a refund to credit is taken back in full and leaves no remainder, so
// the payment is refunded at the provider instead (the client is then paid twice by the dispute).
test('M1: a dispute it cannot take back from credit raises a staff to-do', function () {
    $client = billingClient();
    $payment = payInvoice(issueInvoice($client, [5_000]), 5_000, 'ch_m1', 'sandbox_card');
    app(RefundTransaction::class)->handle($payment, 5_000, RefundMode::RecordOnly, gatewayTxnId: 're_m1');
    $before = collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->sum('count');

    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_m1', 'parent_txn_id' => 'ch_m1', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();

    expect(collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->sum('count'))->toBeGreaterThan($before);
});

// M2 — FAILS at ee927c1e: a dispute of a duplicate payment moves the invoice the first payment fully paid to Collections.
test('M2: a dispute of a payment that went wholly to credit leaves the paid invoice paid', function () {
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    payInvoice($invoice, 5_000, 'ch_m2a', 'sandbox_card');
    payInvoice($invoice, 5_000, 'ch_m2b', 'sandbox_card');

    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_m2', 'parent_txn_id' => 'ch_m2b', 'amount' => 5_000, 'currency' => 'USD'])
        ->assertOk()->assertJson(['result' => 'chargeback recorded; credit taken back']);

    expect($invoice->fresh()->balance)->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

// M3 — FAILS at ee927c1e: the refund row carries the invoice part only, unlike the chargeback row (net income +3,000).
test('M3: a provider refund of an overpayment books the whole refunded amount as money out', function () {
    $client = billingClient();
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => issueInvoice($client, [5_000])->id, 'txn_id' => 'ch_m3', 'amount' => 8_000, 'currency' => 'USD'])->assertOk();

    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_m3', 'parent_txn_id' => 'ch_m3', 'amount' => 8_000, 'currency' => 'USD'])
        ->assertOk()->assertJson(['result' => 'refund recorded; credit taken back']);

    expect((int) Transaction::query()->where('type', 'refund')->sum('amount_out'))->toBe(8_000);
});

// Verified: the to-do and the filter are behind billing.credit.manage.
test('the owing to-do and filter need billing.credit.manage', function () {
    $client = billingClient();
    app(CreditLedgerService::class)->post($client->id, Money::of(-1_000, 'USD'), CreditReason::Reversal, allowNegative: true);

    $staff = actingAsStaff(['billing.invoices.view', 'billing.payments.manage']);
    expect(collect(app(Dashboard::class)->resolveTodos('admin', $staff))->firstWhere('key', 'billing.credit_owed'))->toBeNull();
    $this->get(route('admin.billing.credit.index', ['owing' => 1]))->assertForbidden();

    $manager = actingAsStaff(['billing.credit.manage']);
    expect(collect(app(Dashboard::class)->resolveTodos('admin', $manager))->firstWhere('key', 'billing.credit_owed')['count'])->toBe(1);
    $this->get(route('admin.billing.credit.index', ['owing' => 1]))->assertOk();
});

// Verified: partial refund, then a dispute of the remainder, then a re-reported dispute never exceed what was placed.
test('partial refund then disputes never take back more than the overpayment placed', function () {
    $client = billingClient();
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => issueInvoice($client, [5_000])->id, 'txn_id' => 'ch_v2', 'amount' => 8_000, 'currency' => 'USD'])->assertOk();
    // Added after the invoice, so invoice generation does not auto-apply it.
    app(CreditLedgerService::class)->post($client->id, Money::of(1_500, 'USD'), CreditReason::Manual);
    expect(probeBalance($client->id))->toBe(4_500);

    // Credit first (#194): the 3,000 the overpayment placed, then 3,000 of the invoice.
    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_v2', 'parent_txn_id' => 'ch_v2', 'amount' => 6_000, 'currency' => 'USD'])->assertOk();
    expect(probeBalance($client->id))->toBe(1_500);

    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_v2a', 'parent_txn_id' => 'ch_v2', 'amount' => 2_000, 'currency' => 'USD'])->assertOk();
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_v2b', 'parent_txn_id' => 'ch_v2', 'amount' => 8_000, 'currency' => 'USD'])->assertOk();

    // 3,000 placed, 3,000 taken back in total; the manual 1,500 is untouched.
    expect(probeBalance($client->id))->toBe(1_500);
});

// Verified: another currency's credit is never touched, and a client negative in two currencies counts once.
test('a dispute only touches credit in the payment currency', function () {
    $client = billingClient();
    app(CreditLedgerService::class)->post($client->id, Money::of(2_000, 'NPR'), CreditReason::Manual);
    app(CreditLedgerService::class)->post($client->id, Money::of(-2_500, 'NPR'), CreditReason::Reversal, allowNegative: true);
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => issueInvoice($client, [5_000])->id, 'txn_id' => 'ch_v3', 'amount' => 8_000, 'currency' => 'USD'])->assertOk();
    app(CreditLedgerService::class)->post($client->id, Money::of(-3_000, 'USD'), CreditReason::AppliedToInvoice);

    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_v3', 'parent_txn_id' => 'ch_v3', 'amount' => 8_000, 'currency' => 'USD'])->assertOk();

    expect(probeBalance($client->id))->toBe(-3_000)
        ->and(probeBalance($client->id, 'NPR'))->toBe(-500)
        ->and(collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.credit_owed')['count'])->toBe(1);
});

// Verified: a combined charge whose excess went to credit is taken back exactly once.
test('a dispute of a combined charge takes back the batch excess', function () {
    $client = billingClient();
    $a = issueInvoice($client, [1_000]);
    $b = issueInvoice($client, [2_000]);
    $batch = app(PaymentBatches::class)->create($client->id, [$a->id, $b->id]);
    payInvoice($b, 2_000);
    app(PaymentBatches::class)->settle($batch, 'sandbox_card', 'ch_v4', 3_000);
    expect(probeBalance($client->id))->toBe(2_000);

    $dispute = ['type' => 'charge.dispute', 'txn_id' => 'dp_v4', 'parent_txn_id' => 'ch_v4', 'amount' => 3_000, 'currency' => 'USD'];
    sandboxWebhook($dispute)->assertOk()->assertJson(['result' => 'chargeback recorded; credit taken back']);
    sandboxWebhook($dispute)->assertOk()->assertJson(['result' => 'duplicate']);

    expect(probeBalance($client->id))->toBe(0)
        ->and((int) Transaction::query()->where('type', 'chargeback')->sum('amount_out'))->toBe(3_000);
});

// Verified: the unique-index fallback (a racing delivery commits its line between the exists() check and the insert)
// rolls back the whole dispute, chargeback row and payment update included, and answers "duplicate".
test('the credit_ledger unique index rolls back the whole dispute when the lock check is slipped', function () {
    $client = billingClient();
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => issueInvoice($client, [5_000])->id, 'txn_id' => 'ch_v5', 'amount' => 8_000, 'currency' => 'USD'])->assertOk();

    $raced = false;
    CreditLedgerEntry::creating(function ($entry) use (&$raced, $client): void {
        if ($raced || $entry->external_ref !== 'dp_v5') {
            return;
        }
        $raced = true;
        DB::table('credit_ledger')->insert([
            'client_id' => $client->id, 'currency' => 'USD', 'amount' => -3_000, 'balance_after' => 0, 'reason' => 'reversal',
            'gateway' => 'sandbox_card', 'external_ref' => 'dp_v5', 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_v5', 'parent_txn_id' => 'ch_v5', 'amount' => 8_000, 'currency' => 'USD'])
        ->assertOk()->assertJson(['result' => 'duplicate']);

    expect($raced)->toBeTrue()
        ->and(Transaction::query()->where('type', 'chargeback')->exists())->toBeFalse()
        ->and(Transaction::query()->where('gateway_txn_id', 'ch_v5')->sole()->refundable_remaining)->toBe(5_000)
        ->and(probeBalance($client->id))->toBe(3_000);
});
