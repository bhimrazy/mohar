<?php

use App\Contracts\Billing\CreditLedger;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/WebhookHelpers.php';

/**
 * Review probes for PR #211 (#191 won disputes). Each test states the behaviour the review expects.
 */
beforeEach(function () {
    sandboxGateway();
});

function p211Balance(Client $client): int
{
    return app(CreditLedger::class)->balance($client->id, 'USD')->amount;
}

/**
 * An 8,000 payment of a 5,000 invoice (3,000 to credit), disputed in full.
 *
 * @return array{Client, int}
 */
function p211Disputed(string $id): array
{
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => "ch_$id", 'amount' => 8_000, 'currency' => 'USD'])->assertOk();
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => "dp_$id", 'parent_txn_id' => "ch_$id", 'amount' => 8_000, 'currency' => 'USD'])->assertOk();

    return [$client, $invoice->id];
}

test('probe A: with reversal.set_collections off, a won dispute still pays the invoice again', function () {
    billingSettings(['reversal.set_collections' => false]);
    [$client, $invoiceId] = p211Disputed('pa');

    // The chargeback leaves the invoice "paid" with 5,000 owing (status kept, setting off).
    sandboxWebhook(['type' => 'charge.dispute.won', 'txn_id' => 'dp_pa', 'parent_txn_id' => 'ch_pa', 'amount' => 8_000, 'currency' => 'USD'])->assertOk();

    $reversal = Transaction::query()->where('type', 'chargeback_reversal')->sole();
    expect($reversal->review_amount)->toBeNull()
        ->and(Transaction::query()->where('gateway_txn_id', 'ch_pa')->sole()->refundable_remaining)->toBe(5_000);
});

test('probe B: a provider refund after a won dispute takes back the credit the win restored', function () {
    [$client] = p211Disputed('pb');
    sandboxWebhook(['type' => 'charge.dispute.won', 'txn_id' => 'dp_pb', 'parent_txn_id' => 'ch_pb', 'amount' => 8_000, 'currency' => 'USD'])
        ->assertOk()->assertJson(['result' => 'dispute won; payment reinstated']);
    expect(p211Balance($client))->toBe(3_000);

    // The merchant refunds the whole charge at the provider: same state as before the dispute, so the 3,000 the
    // overpayment placed comes back off the credit and nothing is left for staff review.
    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_pb', 'parent_txn_id' => 'ch_pb', 'amount' => 8_000, 'currency' => 'USD'])
        ->assertOk()->assertJson(['result' => 'refund recorded; credit taken back']);

    expect(p211Balance($client))->toBe(0);
});

describe('probe C (PostgreSQL): two checkouts of one client that insert a client row before issuing with credit', function () {
    beforeEach(function () {
        if (Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    // PlaceOrder inserts the order (a key-share lock on the client) before IssueInvoice locks the client FOR UPDATE.
    // Two such transactions of one client each hold a key share and wait for the other's: 40P01. The review expects
    // both to finish (one paid by credit, one unpaid), as for the renewal path.
    test('neither checkout fails with a deadlock', function () {
        $this->useSeededDatabase('p211_checkout', function (): void {
            $client = billingClient(['email' => 'p211@example.test']);
            app(CreditLedger::class)->credit($client->id, App\Support\Money::of(1_000, 'USD'), 'Goodwill');
        });
        $client = Client::query()->where('email', 'p211@example.test')->sole();
        $script = __DIR__.'/Support/pr211-checkout-worker.php';
        $processes = [];

        // The first inserts its order row and pauses 1 s before issuing; the second starts 0.3 s later and issues at once.
        foreach ([[0, 1_000], [300, 0]] as [$delay, $childPause]) {
            $command = [PHP_BINARY, $script, Illuminate\Support\Facades\DB::connection()->getDatabaseName(), (string) $client->id, '1000', (string) $delay, '0', '0', '1', (string) $childPause];
            $processes[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
        }

        $outputs = [];

        foreach ($processes as [$process, $pipes]) {
            $outputs[] = trim(stream_get_contents($pipes[1]).' '.stream_get_contents($pipes[2]));
            proc_close($process);
        }

        foreach ($outputs as $out) {
            expect($out)->not->toContain('40P01');
            expect($out)->not->toStartWith('error');
        }
    });
});
