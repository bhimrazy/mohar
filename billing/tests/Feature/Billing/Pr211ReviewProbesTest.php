<?php

use App\Contracts\Billing\CreditLedger;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/WebhookHelpers.php';
require_once __DIR__.'/Support/race.php';

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
        ->and(Transaction::query()->where('gateway_txn_id', 'ch_pa')->sole()->refundable_remaining)->toBe(5_000)
        ->and(Invoice::query()->findOrFail($invoiceId))->status->toBe(InvoiceStatus::Paid)->balance->toBe(0)
        ->and(p211Balance($client))->toBe(3_000);
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

describe('probe C (PostgreSQL): two checkouts of one client applying its credit', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    // PlaceOrder inserted the order (a key-share lock on the client) before IssueInvoice locked the client FOR UPDATE.
    // Two such checkouts of one client each held a key share and waited for the other's: 40P01. Adopted with the
    // real PlaceOrder in place of the probe's raw insert; it now locks the client before inserting anything.
    test('neither checkout fails with a deadlock', function () {
        $this->useSeededDatabase('p211_checkout', function (): void {
            $client = billingClient(['email' => 'p211@example.test']);
            app(CreditLedger::class)->credit($client->id, Money::of(1_000, 'USD'), 'Goodwill');
        });
        $client = Client::query()->where('email', 'p211@example.test')->sole();
        $database = DB::connection()->getDatabaseName();

        // "slow" inserts its order and pauses 1 s before issuing; "fast" starts 0.3 s later and issues at once.
        $results = runRaceWorkers([
            'slow' => ['checkout', $database, (string) $client->id, '1000', '0', '1000'],
            'fast' => ['checkout', $database, (string) $client->id, '1000', '300', '0'],
        ]);

        $sorted = collect($results)->sortBy('issued')->values();
        expect([$sorted[0]['status'], $sorted[0]['applied']])->toBe(['paid', 1_000])
            ->and([$sorted[1]['status'], $sorted[1]['applied']])->toBe(['unpaid', 0])
            ->and(p211Balance($client))->toBe(0);
    });
});
