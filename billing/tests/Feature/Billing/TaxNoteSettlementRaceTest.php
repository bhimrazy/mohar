<?php

use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

/*
| Race tests for settlement and reversal (VAT PR 2, ADR 0024 §4): two processes confirm the same preview state on one
| paid invoice, on committed data, so they need PostgreSQL.
*/

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
    }
});

/** @return array{cap: int, reverse: int, credit_note: int} */
function seedNoteSettlementRaces(): array
{
    test()->useSeededDatabase('note_settlement_races', function (): void {
        Currency::factory()->create();
        Currency::factory()->npr()->create();
        billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
        TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
        $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'email' => 'note-settlement-races@example.test']);

        foreach (['cap', 'reverse'] as $name) {
            payInvoice(issueInvoice($client, [1_000_000]), 1_130_000);
        }

        $issuer = app(TaxNoteIssuer::class);
        $request = NoteRequest::creditAmount(Invoice::query()->where('client_id', $client->id)->orderByDesc('id')->value('id'), 500_000, 'goodwill', 'Goodwill', settlement: 'credit');
        $preview = $issuer->preview($request);
        $issuer->issue($request, $preview->version, $preview->digest);
    });

    $ids = Invoice::query()->where('client_id', Client::query()->where('email', 'note-settlement-races@example.test')->value('id'))->orderBy('id')->pluck('id')->all();

    return ['cap' => $ids[0], 'reverse' => $ids[1], 'credit_note' => TaxNote::query()->where('invoice_id', $ids[1])->value('id')];
}

function settlementRaceStart(): string
{
    return (string) ((int) (microtime(true) * 1000) + 2_500);
}

test('two credit notes to account credit that together exceed the invoice: one is issued and the credit equals it', function () {
    $ids = seedNoteSettlementRaces();
    $database = DB::connection()->getDatabaseName();
    $start = settlementRaceStart();

    $results = runRaceWorkers([
        'first' => ['credit', $database, (string) $ids['cap'], '700000', $start],
        'second' => ['credit', $database, (string) $ids['cap'], '700000', $start],
    ], script: __DIR__.'/Support/note-settle-worker.php');
    $invoice = Invoice::query()->findOrFail($ids['cap']);
    $notes = TaxNote::query()->where('invoice_id', $ids['cap'])->pluck('id');

    expect(collect($results)->pluck('outcome')->sort()->values()->all())->toBeIn([['BillingException', 'issued'], ['StaleNoteException', 'issued']])
        ->and($notes)->toHaveCount(1)
        ->and($invoice)->credited_total->toBe(700_000)->balance->toBe(0)
        ->and((int) DB::table('credit_ledger')->whereIn('tax_note_id', $notes)->sum('amount'))->toBe(700_000);
});

test('two reversals of one credit note: exactly one is issued and the credit is taken back once', function () {
    $ids = seedNoteSettlementRaces();
    $database = DB::connection()->getDatabaseName();
    $start = settlementRaceStart();
    $invoice = Invoice::query()->findOrFail($ids['reverse']);
    $creditBefore = app(CreditLedgerService::class)->balance($invoice->client_id, 'NPR')->amount;

    $results = runRaceWorkers([
        'first' => ['reverse', $database, (string) $ids['reverse'], (string) $ids['credit_note'], $start],
        'second' => ['reverse', $database, (string) $ids['reverse'], (string) $ids['credit_note'], $start],
    ], script: __DIR__.'/Support/note-settle-worker.php');

    expect(collect($results)->pluck('outcome')->sort()->values()->all())->toBeIn([['BillingException', 'issued'], ['StaleNoteException', 'issued']])
        ->and(TaxNote::query()->where('reverses_tax_note_id', $ids['credit_note'])->count())->toBe(1)
        ->and($invoice->fresh())->balance->toBe(0)->debited_total->toBe(500_000)
        ->and(app(CreditLedgerService::class)->balance($invoice->client_id, 'NPR')->amount)->toBe($creditBefore - 500_000);
});
