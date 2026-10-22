<?php

use App\Contracts\Billing\CreditLedger;
use App\Modules\Billing\Actions\CreateAddFundsInvoice;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Models\ClientFlag;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Support\Dashboard\Dashboard;
use App\Support\Money;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/WebhookHelpers.php';

/**
 * #172: a provider dispute or refund takes back the account credit its payment placed, once per dispute or refund id.
 */
beforeEach(function () {
    sandboxGateway();
});

/**
 * An 8,000 payment of a 5,000 invoice: 5,000 on the invoice, 3,000 to account credit.
 */
function overpaidClient(string $charge): Client
{
    $client = billingClient();
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => issueInvoice($client, [5_000])->id, 'txn_id' => $charge, 'amount' => 8_000, 'currency' => 'USD'])->assertOk();

    return $client;
}

function creditBalance(Client $client): int
{
    return app(CreditLedger::class)->balance($client->id, 'USD')->amount;
}

test('a replayed dispute takes the credit back once', function () {
    $client = overpaidClient('ch_r1');
    $dispute = ['type' => 'charge.dispute', 'txn_id' => 'dp_r1', 'parent_txn_id' => 'ch_r1', 'amount' => 8_000, 'currency' => 'USD'];

    sandboxWebhook($dispute)->assertOk()->assertJson(['result' => 'chargeback recorded; credit taken back']);
    sandboxWebhook($dispute)->assertOk()->assertJson(['result' => 'duplicate']);

    expect(creditBalance($client))->toBe(0)
        ->and(Transaction::query()->where('type', 'chargeback')->sole()->amount_out)->toBe(8_000)
        ->and(CreditLedgerEntry::query()->where('external_ref', 'dp_r1')->sole()->reason)->toBe(CreditReason::Reversal);
});

test('a replayed provider refund of a payment that went wholly to credit takes it back once', function () {
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    payInvoice($invoice, 5_000, 'ch_r2a', 'sandbox_card');
    payInvoice($invoice, 5_000, 'ch_r2b', 'sandbox_card');
    $refund = ['type' => 'charge.refunded', 'txn_id' => 're_r2', 'parent_txn_id' => 'ch_r2b', 'amount' => 5_000, 'currency' => 'USD'];

    sandboxWebhook($refund)->assertOk()->assertJson(['result' => 'refund recorded; credit taken back']);
    sandboxWebhook($refund)->assertOk()->assertJson(['result' => 'duplicate']);

    expect(creditBalance($client))->toBe(0)
        ->and(CreditLedgerEntry::query()->where('external_ref', 're_r2')->count())->toBe(1)
        ->and(Transaction::query()->where('type', 'refund')->sole())->gateway_txn_id->toBe('re_r2')->amount_out->toBe(5_000)
        ->and(Transaction::query()->where('gateway_txn_id', 'ch_r2a')->sole()->refundable_remaining)->toBe(5_000);
});

test('a delivery that finds its credit line already written books nothing', function () {
    $client = overpaidClient('ch_r3');
    // A concurrent delivery of the same dispute committed its credit line first.
    app(CreditLedgerService::class)->post($client->id, Money::of(-3_000, 'USD'), CreditReason::Reversal, gateway: 'sandbox_card', externalRef: 'dp_r3');

    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_r3', 'parent_txn_id' => 'ch_r3', 'amount' => 8_000, 'currency' => 'USD'])
        ->assertOk()->assertJson(['result' => 'duplicate']);

    expect(Transaction::query()->where('type', 'chargeback')->exists())->toBeFalse()
        ->and(Transaction::query()->where('gateway_txn_id', 'ch_r3')->sole()->refundable_remaining)->toBe(5_000)
        ->and(creditBalance($client))->toBe(0);
});

test('credit the client already spent goes negative and becomes a staff to-do', function () {
    $client = overpaidClient('ch_r4');
    app(CreditLedgerService::class)->post($client->id, Money::of(-3_000, 'USD'), CreditReason::AppliedToInvoice);

    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_r4', 'parent_txn_id' => 'ch_r4', 'amount' => 8_000, 'currency' => 'USD'])->assertOk();

    $staff = billingStaff();
    expect(creditBalance($client))->toBe(-3_000)
        ->and(collect(app(Dashboard::class)->resolveTodos('admin', $staff))->firstWhere('key', 'billing.credit_owed')['count'])->toBe(1);

    $this->get(route('admin.billing.credit.index', ['owing' => 1]))->assertInertia(fn (Assert $page) => $page
        ->where('owing', true)
        ->where('entries.data', fn ($rows) => collect($rows)->pluck('client.id')->all() === [$client->id]));
});

test('partial refunds never take back more credit than the payment placed', function () {
    $client = overpaidClient('ch_r5');

    // Credit first (#194): all 3,000 the payment placed, then 3,000 of the invoice.
    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_r5a', 'parent_txn_id' => 'ch_r5', 'amount' => 6_000, 'currency' => 'USD'])
        ->assertOk()->assertJson(['result' => 'refund recorded; credit taken back']);
    expect(creditBalance($client))->toBe(0);

    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_r5b', 'parent_txn_id' => 'ch_r5', 'amount' => 3_000, 'currency' => 'USD'])
        ->assertOk()->assertJson(['result' => 'refund needs staff review']);
    expect(creditBalance($client))->toBe(0);
});

test('a dispute beyond the payment and its credit leaves other credit alone and goes to staff review', function () {
    $client = billingClient();
    payInvoice(issueInvoice($client, [5_000]), 5_000, 'ch_r6', 'sandbox_card');
    app(CreditLedgerService::class)->post($client->id, Money::of(2_000, 'USD'), CreditReason::Manual);

    // The provider reverses more than the payment and its credit can cover: all of it is money out, the rest is reviewed.
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_r6', 'parent_txn_id' => 'ch_r6', 'amount' => 5_500, 'currency' => 'USD'])
        ->assertOk()->assertJson(['result' => 'chargeback needs staff review']);

    expect(creditBalance($client))->toBe(2_000)
        ->and(Transaction::query()->where('type', 'chargeback')->sole())->amount_out->toBe(5_500)->review_amount->toBe(500)
        ->and(collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.reversals_review')['count'])->toBe(1);
});

test('staff mark a reversal reviewed, which clears it from the to-do', function () {
    $client = billingClient();
    payInvoice(issueInvoice($client, [5_000]), 5_000, 'ch_r7', 'sandbox_card');
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_r7', 'parent_txn_id' => 'ch_r7', 'amount' => 6_000, 'currency' => 'USD'])->assertOk();
    $chargeback = Transaction::query()->where('type', 'chargeback')->sole();

    actingAsStaff(['billing.transactions.view']);
    $this->withSession(['auth.password_confirmed_at' => time()])->post(route('admin.billing.transactions.reviewed', $chargeback))->assertForbidden();

    $staff = billingStaff();
    $this->get(route('admin.billing.transactions.index', ['review' => 1]))->assertInertia(fn (Assert $page) => $page
        ->where('transactions.data', fn ($rows) => collect($rows)->pluck('to_review')->all() === [1_000]));
    $this->withSession(['auth.password_confirmed_at' => time()])->post(route('admin.billing.transactions.reviewed', $chargeback))->assertRedirect();

    expect($chargeback->fresh()->reviewed_at)->not->toBeNull()
        ->and(collect(app(Dashboard::class)->resolveTodos('admin', $staff))->firstWhere('key', 'billing.reversals_review'))->toBeNull();
});

/**
 * #223: a deposit (add-funds invoice) of 5,000 paid by one sandbox charge; its 5,000 sits in account credit.
 */
function paidDeposit(Client $client, string $charge, int $paid = 5_000): Invoice
{
    ClientFlag::query()->create(['client_id' => $client->id, 'first_order_accepted_at' => now()]);
    $deposit = app(CreateAddFundsInvoice::class)->handle($client, 5_000);
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $deposit->id, 'txn_id' => $charge, 'amount' => $paid, 'currency' => 'USD'])->assertOk();

    return $deposit;
}

test('a provider refund of a deposit takes the deposit credit back, once (#223)', function () {
    $client = billingClient();
    $deposit = paidDeposit($client, 'ch_d1');
    expect(creditBalance($client))->toBe(5_000);
    $refund = ['type' => 'charge.refunded', 'txn_id' => 're_d1', 'parent_txn_id' => 'ch_d1', 'amount' => 5_000, 'currency' => 'USD'];

    sandboxWebhook($refund)->assertOk()->assertJson(['result' => 'refund recorded']);
    sandboxWebhook($refund)->assertOk()->assertJson(['result' => 'duplicate']);

    expect(creditBalance($client))->toBe(0)
        ->and($deposit->fresh()->status)->toBe(InvoiceStatus::Refunded)
        ->and(CreditLedgerEntry::query()->where('client_id', $client->id)->where('amount', '<', 0)->sole())
        ->amount->toBe(-5_000)->reason->toBe(CreditReason::Reversal)->invoice_id->toBe($deposit->id);
});

test('a deposit refunded in parts never takes back more than was deposited (#223)', function () {
    $client = billingClient();
    // 6,000 paid on a 5,000 deposit: 5,000 deposit credit plus 1,000 overpayment credit.
    paidDeposit($client, 'ch_d2', 6_000);
    expect(creditBalance($client))->toBe(6_000);

    // Credit first: the 1,000 overpayment, then 1,000 of the deposit.
    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_d2a', 'parent_txn_id' => 'ch_d2', 'amount' => 2_000, 'currency' => 'USD'])->assertOk();
    expect(creditBalance($client))->toBe(4_000);

    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_d2b', 'parent_txn_id' => 'ch_d2', 'amount' => 4_000, 'currency' => 'USD'])->assertOk();
    expect(creditBalance($client))->toBe(0)
        ->and(-(int) CreditLedgerEntry::query()->where('client_id', $client->id)->where('amount', '<', 0)->sum('amount'))->toBe(6_000);
});

test('a refunded deposit whose credit was spent leaves the client owing it, on the to-do (#223)', function () {
    $client = billingClient();
    paidDeposit($client, 'ch_d3');
    issueInvoice($client, [3_000], applyCredit: true);
    expect(creditBalance($client))->toBe(2_000);

    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_d3', 'parent_txn_id' => 'ch_d3', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();

    expect(creditBalance($client))->toBe(-3_000)
        ->and(collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.credit_owed')['count'])->toBe(1);
});

test('a staff refund of a deposit takes the deposit credit back too (#223)', function (RefundMode $mode) {
    $client = billingClient();
    $deposit = paidDeposit($client, 'ch_d4');
    $payment = Transaction::query()->where('invoice_id', $deposit->id)->where('type', 'payment')->sole();

    app(RefundTransaction::class)->handle($payment, 5_000, $mode);

    // To credit: the refund's 5,000 replaces the deposit's 5,000, so nothing is added.
    expect(creditBalance($client))->toBe($mode === RefundMode::ToCredit ? 5_000 : 0);
})->with([RefundMode::RecordOnly, RefundMode::ToCredit]);
