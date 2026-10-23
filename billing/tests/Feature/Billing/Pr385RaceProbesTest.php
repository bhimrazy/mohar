<?php

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\FiscalBackfill;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only.');
    }
});

/** @return list<int> */
function rbRaceSeed(string $name, int $count): array
{
    test()->useSeededDatabase($name, function () use ($count): void {
        Currency::factory()->create();
        Currency::factory()->npr()->create();
        billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR',
            'late_fee.amount' => 10, 'late_fee.type' => 'percent', 'late_fee.base' => 'balance']);
        TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
        $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'email' => 'rb385@example.test']);

        for ($i = 0; $i < $count; $i++) {
            issueInvoice($client, [10_000, 3_333]);
        }
    });

    return Invoice::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

function rbStart(): string
{
    return (string) ((int) (microtime(true) * 1000) + 2_500);
}

function rbRun(array $workers): array
{
    return runRaceWorkers($workers, 40.0, __DIR__.'/Support/rb385-worker.php');
}

test('R1: a credit note against a late fee on the same invoice: the fee applies, the note applies or is stale, the caches match the reconcile pass', function () {
    [$id] = rbRaceSeed('rb385_r1', 1);
    $db = DB::connection()->getDatabaseName();
    $s = rbStart();
    $r = rbRun(['note' => ['credit', $db, (string) $id, '5000', $s, '0'], 'fee' => ['latefee', $db, (string) $id, '0', $s, '0']]);
    $invoice = Invoice::query()->findOrFail($id);

    expect($r['note']['outcome'])->toBeIn(['issued', 'StaleNoteException'])->and($r['fee']['outcome'])->toBe('fee')
        ->and($invoice->balance)->toBe($invoice->total + $invoice->adjustments_total - $invoice->amount_paid)
        ->and(app(FiscalBackfill::class)->mismatches())->toBe(0);
});

test('R2: a confirm whose preview predates a concurrent invoice edit is refused as stale', function () {
    [$id] = rbRaceSeed('rb385_r2', 1);
    $db = DB::connection()->getDatabaseName();
    $s = rbStart();
    $r = rbRun(['note' => ['credit', $db, (string) $id, '5000', $s, '1'], 'edit' => ['edit', $db, (string) $id, '0', $s, '0']]);

    expect($r['edit']['outcome'])->toBe('edited')->and($r['note']['outcome'])->toBe('StaleNoteException')
        ->and(TaxNote::query()->where('invoice_id', $id)->count())->toBe(0);
});

test('R3: credit and debit notes on six invoices at once, with a reconcile pass: two gap-free sequences, no deadlock', function () {
    $ids = rbRaceSeed('rb385_r3', 6);
    $db = DB::connection()->getDatabaseName();
    $s = rbStart();
    $workers = ['rec' => ['reconcile', $db, '0', '0', $s, '0']];
    foreach ($ids as $i => $id) {
        $workers["c$i"] = ['credit', $db, (string) $id, '1000', $s, '0'];
        $workers["d$i"] = ['debit', $db, (string) $id, '113', $s, '0'];
    }
    $r = rbRun($workers);
    $outcomes = collect($r)->except('rec')->pluck('outcome')->unique()->values()->all();
    $credit = TaxNote::query()->where('kind', 'credit')->pluck('number')->map(fn ($n) => (int) substr($n, 3))->sort()->values()->all();
    $debit = TaxNote::query()->where('kind', 'debit')->pluck('number')->map(fn ($n) => (int) substr($n, 3))->sort()->values()->all();

    // A note that lost the version race is refused, never half-written; what was issued is numbered 1..n without a gap.
    expect($credit)->toBe(range(1, count($credit)))->and($debit)->toBe(range(1, count($debit)))
        ->and($outcomes)->each->toBeIn(['issued', 'StaleNoteException'])
        ->and(app(FiscalBackfill::class)->mismatches())->toBe(0);
});

test('R4: a note and a refund-style finalise that holds the client before the sequence do not deadlock', function () {
    [$id] = rbRaceSeed('rb385_r4', 1);
    $db = DB::connection()->getDatabaseName();
    $s = rbStart();
    // The finalise path (PR 4) locks the client, then asks for the next number; the note must take the client first too.
    $r = rbRun(['fin' => ['finalise', $db, (string) $id, '0', $s, '0'], 'note' => ['credit', $db, (string) $id, '1000', (string) ((int) $s + 150), '0']]);

    expect($r['fin']['outcome'])->toBe('finalised')->and($r['note']['outcome'])->toBe('issued')
        ->and($r['fin']['number'])->not->toBe($r['note']['number']);
});
