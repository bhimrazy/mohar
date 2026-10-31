<?php

use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\ActivityLog;
use App\Support\Dashboard\Dashboard;

require_once __DIR__.'/../Billing/helpers.php';
require_once __DIR__.'/../Billing/WebhookHelpers.php';

/**
 * Re-check probes for PR #181 at 35b9a779.
 */
beforeEach(function () {
    sandboxGateway();
});

function recheckReviewTodo(): ?array
{
    return collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.reversals_review');
}

test('M1 refund side: a provider refund beyond the payment and its credit raises the review to-do', function () {
    $client = billingClient();
    payInvoice(issueInvoice($client, [5_000]), 5_000, 'ch_rc1', 'sandbox_card');

    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_rc1', 'parent_txn_id' => 'ch_rc1', 'amount' => 6_000, 'currency' => 'USD'])
        ->assertOk()->assertJson(['result' => 'refund needs staff review']);

    expect(Transaction::query()->where('type', 'refund')->sole())->amount_out->toBe(6_000)->review_amount->toBe(1_000)
        ->and(recheckReviewTodo()['count'])->toBe(1);
});

test('mark reviewed is idempotent: one activity entry, and a second post changes nothing', function () {
    $client = billingClient();
    payInvoice(issueInvoice($client, [5_000]), 5_000, 'ch_rc2', 'sandbox_card');
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_rc2', 'parent_txn_id' => 'ch_rc2', 'amount' => 6_000, 'currency' => 'USD'])->assertOk();
    $chargeback = Transaction::query()->where('type', 'chargeback')->sole();

    billingStaff();
    $this->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()])->post(route('admin.billing.transactions.reviewed', $chargeback))->assertRedirect();
    $first = $chargeback->fresh()->reviewed_at;
    $this->travel(5)->minutes();
    $this->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()])->post(route('admin.billing.transactions.reviewed', $chargeback))->assertRedirect();

    expect($chargeback->fresh()->reviewed_at->equalTo($first))->toBeTrue()
        ->and(ActivityLog::query()->where('event', 'billing.reversal.reviewed')->count())->toBe(1);
});

test('mark reviewed needs a recent password and a reversal with a review amount', function () {
    $client = billingClient();
    $payment = payInvoice(issueInvoice($client, [5_000]), 5_000, 'ch_rc3', 'sandbox_card');
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_rc3', 'parent_txn_id' => 'ch_rc3', 'amount' => 6_000, 'currency' => 'USD'])->assertOk();
    $chargeback = Transaction::query()->where('type', 'chargeback')->sole();

    billingStaff();
    $this->post(route('admin.billing.transactions.reviewed', $chargeback))->assertRedirect()->assertSessionHasErrors();
    expect($chargeback->fresh()->reviewed_at)->toBeNull()
        ->and(ActivityLog::query()->where('event', 'billing.reversal.reviewed')->exists())->toBeFalse();

    $this->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()])->post(route('admin.billing.transactions.reviewed', $payment))->assertNotFound();
});

// The to-do is gated on billing.payments.manage but links to a page gated on billing.transactions.view.
test('staff who see the review to-do can open the page it links to', function () {
    $client = billingClient();
    payInvoice(issueInvoice($client, [5_000]), 5_000, 'ch_rc4', 'sandbox_card');
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_rc4', 'parent_txn_id' => 'ch_rc4', 'amount' => 6_000, 'currency' => 'USD'])->assertOk();

    // Adopted with the CTO's fix (show the to-do only to staff who hold both permissions): staff with only
    // billing.payments.manage no longer see it, and staff who see it can open its link.
    $partial = actingAsStaff(['billing.payments.manage']);
    expect(collect(app(Dashboard::class)->resolveTodos('admin', $partial))->firstWhere('key', 'billing.reversals_review'))->toBeNull();

    $staff = actingAsStaff(['billing.payments.manage', 'billing.transactions.view']);
    $todo = collect(app(Dashboard::class)->resolveTodos('admin', $staff))->firstWhere('key', 'billing.reversals_review');
    expect($todo)->not->toBeNull();

    $this->get($todo['href'])->assertOk();
});
