<?php

use App\Models\User;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

/*
| Race tests for the old paths in the mode (VAT PR 3b, ADR 0024 §4): two processes on committed data, so PostgreSQL.
*/

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
    }
});

/** @return array{pay: int, fee: int} two numbered unpaid invoices of 11,300 */
function seedOldPathRaces(): array
{
    test()->useSeededDatabase('vat_old_path_races', function (): void {
        Currency::factory()->create();
        Currency::factory()->npr()->create();
        billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR', 'late_fee.type' => 'fixed', 'late_fee.amount' => 1_130, 'late_fee.minimum' => 0]);
        useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
        User::factory()->staff(['billing.*'])->create();
        $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR', 'email' => 'vat-old-path-races@example.test']);
        issueInvoice($client, [10_000]);
        issueInvoice($client, [10_000]);
    });

    $ids = Invoice::query()->where('client_id', Client::query()->where('email', 'vat-old-path-races@example.test')->value('id'))->orderBy('id')->pluck('id')->all();

    return ['pay' => $ids[0], 'fee' => $ids[1]];
}

function oldPathRaceStart(): string
{
    return (string) ((int) (microtime(true) * 1000) + 2_500);
}

test('a staff cancel and a full payment of the same numbered invoice: one wins, and the books agree', function () {
    $id = seedOldPathRaces()['pay'];
    $database = DB::connection()->getDatabaseName();
    $start = oldPathRaceStart();

    runRaceWorkers([
        'cancel' => ['cancel', $database, (string) $id, $start],
        'pay' => ['pay', $database, (string) $id, $start, '11300'],
    ], script: __DIR__.'/Support/old-path-worker.php');
    $invoice = Invoice::query()->findOrFail($id);
    $notes = TaxNote::query()->where('invoice_id', $id)->count();

    // Paid first: the cancel finds it paid and does nothing (no note). Cancelled first: its credit note zeroed the balance.
    expect($invoice->status)->toBeIn([InvoiceStatus::Paid, InvoiceStatus::Cancelled])
        ->and($invoice->balance)->toBeLessThanOrEqual(0)
        ->and($invoice->status === InvoiceStatus::Paid ? $notes === 0 : $notes === 1)->toBeTrue();
});

test('a staff cancel and the late fee of the same numbered invoice: the note covers the fee exactly when the fee came first', function () {
    $id = seedOldPathRaces()['fee'];
    $database = DB::connection()->getDatabaseName();
    $start = oldPathRaceStart();

    runRaceWorkers([
        'cancel' => ['cancel', $database, (string) $id, $start],
        'fee' => ['fee', $database, (string) $id, $start],
    ], script: __DIR__.'/Support/old-path-worker.php');
    $invoice = Invoice::query()->findOrFail($id);

    // Whichever ran first, the invoice ends cancelled with nothing left owing, and a late fee added before the cancel is in its note.
    expect($invoice)->status->toBe(InvoiceStatus::Cancelled)->balance->toBe(0)
        ->and((int) TaxNote::query()->where('invoice_id', $id)->where('kind', 'credit')->sum('total'))->toBe(11_300 + (int) TaxNote::query()->where('invoice_id', $id)->where('kind', 'debit')->sum('total'));
});
