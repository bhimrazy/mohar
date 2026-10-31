<?php

use App\Contracts\Billing\CreditLedger;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentBatch;
use App\Modules\Core\Models\Client;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('pgsql only');
    }
});

test('recheck R1: a combined payment of A and B and an overpayment of B alone both book', function () {
    $this->useSeededDatabase('recheck_batch_over', function (): void {
        $client = billingClient(['email' => 'batch-over@example.test']);
        $a = issueInvoice($client, [1_000]);
        $b = issueInvoice($client, [1_000]);
        PaymentBatch::query()->create(['client_id' => $client->id, 'currency' => 'USD', 'invoice_ids' => [$a->id, $b->id], 'total' => 2_000, 'status' => 'processing']);
    });
    $client = Client::query()->where('email', 'batch-over@example.test')->sole();
    [, $b] = Invoice::query()->where('client_id', $client->id)->orderBy('id')->pluck('id')->all();
    $batch = PaymentBatch::query()->where('client_id', $client->id)->sole();
    $database = DB::connection()->getDatabaseName();

    $results = runRaceWorkers([
        'batch' => ['batch', $database, (string) $batch->id, '2000', '0', '1500'],
        'single' => ['pay', $database, (string) $b, '1500', '300', '0'],
    ]);

    expect($results)->toHaveKeys(['batch', 'single'])
        ->and(Invoice::query()->where('client_id', $client->id)->pluck('status')->map(fn ($s) => $s->value)->all())->each->toBe('paid');
    // Batch 2,000 on 2,000 owed + single 1,500 on B: 1,500 ends as credit, whichever order wins B.
    expect(app(CreditLedger::class)->balance($client->id, 'USD')->amount)->toBe(1_500);
});
