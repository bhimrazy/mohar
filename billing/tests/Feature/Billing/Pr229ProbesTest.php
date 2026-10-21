<?php

use App\Contracts\Billing\CreditLedger;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentBatch;
use App\Modules\Core\Models\Client;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

/**
 * Review probes for PR #229 (#224 lock order). PostgreSQL only: separate committed sessions.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
    }
});

test('probe B1: a combined payment of invoices A and B and a payment of B alone both book (no deadlock)', function () {
    $this->useSeededDatabase('pr229_batch_race', function (): void {
        $client = billingClient(['email' => 'batch-race@example.test']);
        $a = issueInvoice($client, [1_000]);
        $b = issueInvoice($client, [1_000]);
        PaymentBatch::query()->create(['client_id' => $client->id, 'currency' => 'USD', 'invoice_ids' => [$a->id, $b->id], 'total' => 2_000, 'status' => 'processing']);
    });
    $client = Client::query()->where('email', 'batch-race@example.test')->sole();
    [, $b] = Invoice::query()->where('client_id', $client->id)->orderBy('id')->pluck('id')->all();
    $batch = PaymentBatch::query()->where('client_id', $client->id)->sole();
    $database = DB::connection()->getDatabaseName();

    // The batch pays A (locks A, then, since 1,000 >= balance, the client FOR UPDATE), pauses, then locks B.
    // Meanwhile a payment of exactly B's balance locks B, then waits for the client: invoice A -> client -> invoice B
    // against invoice B -> client.
    $results = runRaceWorkers([
        'batch' => ['batch', $database, (string) $batch->id, '2000', '0', '1500'],
        'single' => ['pay', $database, (string) $b, '1000', '300', '0'],
    ]);

    expect($results)->toHaveKeys(['batch', 'single']);
});

test('probe B2: a payment on an existing invoice and a new invoice that applies credit, same client, both book', function (string $first) {
    $this->useSeededDatabase('pr229_issue_pay_'.$first, function (): void {
        $client = billingClient(['email' => 'issue-pay@example.test']);
        issueInvoice($client, [1_000]);
        app(CreditLedger::class)->credit($client->id, Money::of(200, 'USD'), 'Goodwill');
    });
    $client = Client::query()->where('email', 'issue-pay@example.test')->sole();
    $a = Invoice::query()->where('client_id', $client->id)->sole()->id;
    $database = DB::connection()->getDatabaseName();

    $results = runRaceWorkers($first === 'issue' ? [
        // Issue holds the client lock (pause in the order guard, after publish), the payment waits.
        'issue' => ['issue', $database, (string) $client->id, '1000', '0', '1500'],
        'pay' => ['pay', $database, (string) $a, '1500', '300', '0'],
    ] : [
        // The payment holds invoice A and the client (pause after its insert), the new invoice waits.
        'pay' => ['pay', $database, (string) $a, '1500', '0', '1500'],
        'issue' => ['issue', $database, (string) $client->id, '1000', '300', '0'],
    ]);

    expect($results['pay']['status'])->toBe('paid')
        ->and(app(CreditLedger::class)->balance($client->id, 'USD')->amount)->toBe($first === 'issue' ? 500 : 0);
})->with(['issue', 'pay']);
