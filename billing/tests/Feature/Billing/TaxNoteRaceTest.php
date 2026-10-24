<?php

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

/*
| Race tests for notes (ADR 0024 §4, vat-register AC 12): two processes meet on one invoice, on committed data, so they
| need PostgreSQL. The refund race comes with the refund state machine (PR 4), which is what a note and a refund share.
*/

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
    }
});

/** @return array<string, int> name => invoice id */
function seedNoteRaces(): array
{
    $ids = [];

    test()->useSeededDatabase('notes_races', function () use (&$ids): void {
        Currency::factory()->create();
        Currency::factory()->npr()->create();
        billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
        useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
        $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'email' => 'notes-races@example.test']);

        foreach (['cap0', 'cap1', 'payment', 'a', 'b'] as $name) {
            issueInvoice($client, [10_000]);
        }
    });

    foreach (['cap0', 'cap1', 'payment', 'a', 'b'] as $i => $name) {
        $ids[$name] = Invoice::query()->where('client_id', Client::query()->where('email', 'notes-races@example.test')->value('id'))->orderBy('id')->skip($i)->value('id');
    }

    return $ids;
}

/** A start instant a moment ahead, so every worker is booted and waiting when it arrives. */
function raceStart(): string
{
    return (string) ((int) (microtime(true) * 1000) + 2_500);
}

test('two notes on one invoice that together exceed it: exactly one is issued and the caps hold', function (string $previewFirst) {
    // The committed data outlives the test, so each case has an invoice of its own.
    $cap = 'cap'.$previewFirst;
    $ids = seedNoteRaces();
    $database = DB::connection()->getDatabaseName();
    $start = raceStart();

    $results = runRaceWorkers([
        'first' => ['note', $database, (string) $ids[$cap], '6000', $start, $previewFirst],
        'second' => ['note', $database, (string) $ids[$cap], '6000', $start, $previewFirst],
    ], script: __DIR__.'/Support/note-worker.php');
    $outcomes = collect($results)->pluck('outcome')->all();
    $invoice = Invoice::query()->findOrFail($ids[$cap]);

    expect($outcomes)->toContain('issued')->and(array_diff($outcomes, ['issued']))->each->toBeIn(['StaleNoteException', 'BillingException'])->and(array_count_values($outcomes)['issued'])->toBe(1)
        ->and(TaxNote::query()->where('invoice_id', $ids[$cap])->count())->toBe(1)
        ->and($invoice)->credited_total->toBe(6_000)->balance->toBe(5_300)
        ->and($invoice->credited_total)->toBeLessThanOrEqual($invoice->fiscal_total);
})->with(['both preview first' => ['1'], 'each previews after the start' => ['0']]);

test('a note against a payment of the same invoice leaves a balance that is never negative', function () {
    $ids = seedNoteRaces();
    $database = DB::connection()->getDatabaseName();
    $start = raceStart();

    $results = runRaceWorkers([
        'note' => ['note', $database, (string) $ids['payment'], '6000', $start, '0'],
        'pay' => ['pay', $database, (string) $ids['payment'], '11300', $start, '0'],
    ], script: __DIR__.'/Support/note-worker.php');
    $invoice = Invoice::query()->findOrFail($ids['payment']);

    expect($results['pay']['outcome'])->toBe('paid')
        ->and($results['note']['outcome'])->toBeIn(['issued', 'BillingException'])
        ->and($invoice->balance)->toBeGreaterThanOrEqual(0)
        ->and($invoice->balance)->toBe($invoice->total + $invoice->adjustments_total - $invoice->amount_paid)
        ->and(TaxNote::query()->where('invoice_id', $ids['payment'])->count())->toBe($results['note']['outcome'] === 'issued' ? 1 : 0);
});

test('two notes on different invoices get distinct consecutive numbers', function () {
    $ids = seedNoteRaces();
    $database = DB::connection()->getDatabaseName();
    $start = raceStart();

    $results = runRaceWorkers([
        'a' => ['note', $database, (string) $ids['a'], '1000', $start, '0'],
        'b' => ['note', $database, (string) $ids['b'], '1000', $start, '0'],
    ], script: __DIR__.'/Support/note-worker.php');
    $numbers = collect($results)->pluck('number')->map(fn (string $number) => (int) substr($number, 3))->sort()->values()->all();

    expect(collect($results)->pluck('outcome')->all())->toBe(['issued', 'issued'])
        ->and($numbers[1])->toBe($numbers[0] + 1)
        ->and(TaxNote::query()->whereIn('invoice_id', [$ids['a'], $ids['b']])->count())->toBe(2);
});
