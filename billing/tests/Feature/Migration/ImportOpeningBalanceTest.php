<?php

use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Migration\Models\MigrationRun;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Migration\ClientCsv;

/**
 * Client import, opening credit balances and tax-ID notes (client-import spec §6.6, §6.12; AC 19, 34), in NPR.
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    actingAsStaff();
    confirmPassword();
});

/**
 * Upload, check and apply one file of clients with extra columns into the "Old billing" source.
 *
 * @param  list<list<string>>  $rows
 * @param  list<string>  $extra  headers after ClientCsv::HEADER
 */
function importWith(array $rows, array $extra = ['Credit']): MigrationRun
{
    return ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file($rows, [...ClientCsv::HEADER, ...$extra]))));
}

/**
 * @return list<int>
 */
function importEntries(Client $client): array
{
    return CreditLedgerEntry::query()->where('client_id', $client->id)->where('reason', CreditReason::Import)->orderBy('id')->pluck('amount')->all();
}

test('an opening balance posts one import entry in the client currency, and a later run posts only the difference', function () {
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([[...ClientCsv::row('1', 'asha@example.com'), '1,250.50']], [...ClientCsv::HEADER, 'Credit'])));

    $this->get(route('admin.migration.imports.show', $run))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.credit', ['amount' => 125050, 'currency' => 'NPR']));
    ClientCsv::apply($run);

    $client = Client::query()->where('email', 'asha@example.com')->sole();
    $entry = CreditLedgerEntry::query()->where('client_id', $client->id)->sole();
    expect($entry->amount)->toBe(125050)->and($entry->currency)->toBe('NPR')->and($entry->reason)->toBe(CreditReason::Import)
        ->and($entry->description)->toBe('Opening balance from Old billing')
        ->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(125050);

    // The same External ID in the same source with a new amount: an update that posts the difference, never an edit of the first entry.
    $source = ['source_id' => $run->migration_source_id];
    $second = ClientCsv::check(ClientCsv::upload(ClientCsv::file([[...ClientCsv::row('1', 'asha@example.com'), '1,300.00']], [...ClientCsv::HEADER, 'Credit']), $source));
    expect($second->records()->sole()->changes)->toContain('credit_balance');
    $second = ClientCsv::apply($second);

    expect(importEntries($client))->toBe([125050, 4950])
        ->and($second->records()->sole()->outcome)->toBe('updated');

    // The same file again is unchanged: nothing to apply.
    $third = ClientCsv::check(ClientCsv::upload(ClientCsv::file([[...ClientCsv::row('1', 'asha@example.com'), '1,300.00']], [...ClientCsv::HEADER, 'Credit']), $source));
    expect($third->records()->sole()->decision)->toBe('unchanged');
});

test('a negative or malformed amount is a row error and posts nothing; lakh grouping is read', function () {
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([
        [...ClientCsv::row('1', 'a@example.com'), '-5'],
        [...ClientCsv::row('2', 'b@example.com'), '12.345'],
        [...ClientCsv::row('3', 'c@example.com'), '1,25,000.5'],
    ], [...ClientCsv::HEADER, 'Credit'])));
    $records = $run->records()->orderBy('row_number')->get();

    expect($records[0]->status)->toBe('error')->and(array_column($records[0]->problems, 'code'))->toContain('credit_negative')
        ->and($records[1]->status)->toBe('error')->and(array_column($records[1]->problems, 'code'))->toContain('credit_format')
        ->and($records[2]->normalized['extra']['credit_balance'])->toBe(12500050);

    $this->put(route('admin.migration.imports.decide', $run), ['row' => 1, 'decision' => 'skip']);
    ClientCsv::apply($run->fresh());
    expect(CreditLedgerEntry::query()->pluck('amount')->all())->toBe([12500050]);
});

test('a re-run keeps the balance when the client\'s credit moved since the import, and says so', function () {
    $first = importWith([[...ClientCsv::row('1', 'asha@example.com'), '1000']]);
    $client = Client::query()->where('email', 'asha@example.com')->sole();
    app(CreditLedgerService::class)->post($client->id, Money::of(-30000, 'NPR'), CreditReason::AppliedToInvoice);

    $run = ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([[...ClientCsv::row('1', 'asha@example.com'), '2000']], [...ClientCsv::HEADER, 'Credit']), ['source_id' => $first->migration_source_id])));

    expect(importEntries($client))->toBe([100000])
        ->and(array_column($run->records()->sole()->problems, 'code'))->toContain('credit_kept')
        ->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(70000);
});

test('tax numbers get warnings, never errors: Nepal PAN, Indian GSTIN and its state', function () {
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([
        [...ClientCsv::row('1', 'pan-ok@example.com', company: 'Himal Web'), '123 456 789', ''],
        [...ClientCsv::row('2', 'pan-bad@example.com'), '12345678', ''],
        [...ClientCsv::row('3', 'pan-none@example.com', company: 'Patan Crafts'), '', ''],
        [...ClientCsv::row('4', 'gst-state@example.com', country: 'IN'), '29ABCDE1234F1Z5', 'Kerala'],
        [...ClientCsv::row('5', 'gst-bad@example.com', country: 'IN'), '29ABCDE1234', 'KA'],
    ], [...ClientCsv::HEADER, 'Tax ID', 'State'])));
    $records = $run->records()->orderBy('row_number')->get()->keyBy('row_number');
    $codes = fn (int $row): array => array_column($records[$row]->problems, 'code');

    expect($records[1]->normalized['client']['tax_id'])->toBe('123456789')->and($codes(1))->toBe([])
        ->and($codes(2))->toBe(['pan_invalid'])->and($records[2]->status)->toBe('warning')
        ->and($codes(3))->toBe(['pan_missing'])->and($records[3]->status)->toBe('ok')
        ->and($codes(4))->toBe(['gstin_state'])
        ->and(collect($records[4]->problems)->firstWhere('code', 'gstin_state')['message'])->toContain('Karnataka')->toContain('Kerala')
        ->and($codes(5))->toBe(['gstin_invalid'])
        ->and($records->pluck('decision')->unique()->all())->toBe(['create']);
});
