<?php

use App\Contracts\Migration\RevertsImport;
use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportOutcome;
use App\Data\Migration\ImportRecord;
use App\Enums\ImportAction;
use App\Events\Migration\MigrationRunCompleted;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Migration\Models\MigrationIdMap;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Support\SourceIds;
use App\Support\Migration\ImportRegistry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Migration\ClientCsv;

require_once __DIR__.'/../Billing/helpers.php';

/**
 * Re-review probes for PR #259 at 70d64d3c: the parser, the posting rule (§6.12), per-entity storage (ADR 0019
 * addendum), the run counts, the currency error at check, and the check-time dry run. Finished after the codespace stop.
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    actingAsStaff();
    confirmPassword();
});

const PR259R_HEADER = [...ClientCsv::HEADER, 'Credit'];

/**
 * @return array{credit: int|null, codes: list<string>}
 */
function pr259rCredit(string $cell): array
{
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([[...ClientCsv::row('1', 'asha@example.com'), $cell]], PR259R_HEADER)));
    $record = $run->records()->sole();

    return ['credit' => $record->normalized['extra']['credit_balance'] ?? null, 'codes' => array_column($record->problems ?? [], 'code')];
}

/** @return list<int> */
function pr259rImports(Client $client): array
{
    return CreditLedgerEntry::query()->where('client_id', $client->id)->where('reason', CreditReason::Import)->orderBy('id')->pluck('amount')->all();
}

function pr259rRun(string $credit, ?MigrationRun $after = null, string $id = '1', string $email = 'asha@example.com'): MigrationRun
{
    return ClientCsv::check(ClientCsv::upload(ClientCsv::file([[...ClientCsv::row($id, $email), $credit]], PR259R_HEADER),
        $after === null ? [] : ['source_id' => $after->migration_source_id]), ['on_match' => 'update']);
}

test('R-B1 recheck: grouped amounts are read exactly', function (string $cell, int $minor) {
    expect(pr259rCredit($cell))->toBe(['credit' => $minor, 'codes' => []]);
})->with([
    'lakh with one decimal' => ['12,34,567.5', 123456750],
    'western with two decimals' => ['1,234,567.00', 123456700],
    'padded with spaces' => ['  1,000 ', 100000],
    'lakh, 11 digits' => ['99,99,99,99,999', 9999999999900],
]);

test('R-B1 recheck: ambiguous, signed, scientific, non-ASCII, over-precise and overlong amounts are refused', function (string $cell) {
    expect(pr259rCredit($cell))->toBe(['credit' => null, 'codes' => ['credit_format']]);
})->with([
    'decimal comma' => ['250,50'], 'loose grouping' => ['1,2,3'], 'plus sign' => ['+5'], 'exponent' => ['1e3'],
    'Arabic-Indic digits' => ['١٢٣'], 'three decimals' => ['0.001'], 'PHP_INT_MAX' => [(string) PHP_INT_MAX],
    'lakh, 13 digits' => ['12,34,56,78,90,123'], 'mixed grouping' => ['1,234,56,789'], 'trailing point' => ['5.'],
    'leading point' => ['.5'],
]);

test('R-B1 recheck: an empty credit cell is blank, not zero and not an error', function () {
    expect(pr259rCredit(''))->toBe(['credit' => null, 'codes' => []]);
});

test('R-B2 recheck: a staff credit between runs keeps the balance, at check and at apply', function () {
    $first = ClientCsv::apply(pr259rRun('1,000.00'));
    $client = Client::query()->where('email', 'asha@example.com')->sole();
    app(CreditLedgerService::class)->post($client->id, Money::of(20000, 'NPR'), CreditReason::Manual, description: 'Goodwill');

    $second = pr259rRun('1,500.00', $first);
    $checked = $second->records()->sole();
    $applied = ClientCsv::apply($second)->records()->sole();

    expect($checked->decision)->toBe('unchanged')->and($checked->changes)->toBe([])
        ->and(array_column($checked->problems, 'code'))->toContain('credit_kept')
        ->and(pr259rImports($client))->toBe([100000])
        ->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(120000)
        ->and($applied->outcome ?? 'not applied')->not->toBe('updated');
});

test('R-B2 recheck: credit applied to an invoice between runs keeps the balance', function () {
    $first = ClientCsv::apply(pr259rRun('1,000.00'));
    $client = Client::query()->where('email', 'asha@example.com')->sole();
    $invoice = issueInvoice($client, [30000], applyCredit: true);
    expect($invoice->fresh()->status->value)->toBe('paid');

    ClientCsv::apply(pr259rRun('2,000.00', $first));

    expect(pr259rImports($client))->toBe([100000])
        ->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(70000);
});

test('R-B2 recheck: credit that moves after the check but before apply is kept at apply', function () {
    $first = ClientCsv::apply(pr259rRun('1,000.00'));
    $client = Client::query()->where('email', 'asha@example.com')->sole();

    $second = pr259rRun('1,500.00', $first);
    expect($second->records()->sole()->changes)->toBe(['credit_balance']);
    app(CreditLedgerService::class)->post($client->id, Money::of(5000, 'NPR'), CreditReason::Manual, description: 'Between check and apply');
    $record = ClientCsv::apply($second)->records()->sole();

    expect(pr259rImports($client))->toBe([100000])
        ->and(array_column($record->problems, 'code'))->toContain('credit_kept')
        ->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(105000);
});

test('R-B2 recheck: created by the run posts; a re-run with no movement posts the difference', function () {
    $first = ClientCsv::apply(pr259rRun('1,000.00'));
    $client = Client::query()->where('email', 'asha@example.com')->sole();
    ClientCsv::apply(pr259rRun('900.00', $first));

    expect(pr259rImports($client))->toBe([100000, -10000])
        ->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(90000);
});

test('A-B2 recheck: the record stores per-entity outcomes, and undo with the stored credit_balance outcome reverts exactly', function () {
    $run = ClientCsv::apply(pr259rRun('1,250.50'));
    $record = $run->records()->sole();
    $client = Client::query()->findOrFail($record->target_id);

    expect($record->entities['client']['action'])->toBe('created')
        ->and($record->entities['credit_balance'])->toBe(['action' => 'created', 'target_id' => $client->id, 'changes' => ['credit_balance']])
        ->and($record->before)->toBe(['credit_balance' => ['credit_balance' => 0]])
        ->and(MigrationIdMap::query()->where('migration_source_id', $run->migration_source_id)->pluck('entity')->sort()->values()->all())->toBe(['client', 'credit_balance']);

    $stored = $record->entities['credit_balance'];
    $importer = app(ImportRegistry::class)->get('credit_balance');
    expect($importer)->toBeInstanceOf(RevertsImport::class);
    $context = new ImportContext($run->id, $run->migration_source_id, $run->source_system, false, CarbonImmutable::now(), null, new SourceIds($run->migration_source_id));
    $outcome = $importer->revert(
        new ImportRecord('credit_balance', $run->source_system, (string) $record->source_id, ['amount' => 125050], ['client_id' => $client->id]),
        new ImportOutcome($stored['target_id'], ImportAction::from($stored['action']), changes: $stored['changes'], before: $record->before['credit_balance']),
        $context,
    );

    expect($outcome->action)->toBe(ImportAction::Reverted)
        ->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(0)
        ->and(pr259rImports($client))->toBe([125050, -125050]);
});

test('A-B2 recheck: MigrationRunCompleted counts credit_balance outcomes per entity', function () {
    Event::fake([MigrationRunCompleted::class]);
    $first = ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([
        [...ClientCsv::row('1', 'a@example.com'), '100'],
        [...ClientCsv::row('2', 'b@example.com'), '200'],
        [...ClientCsv::row('3', 'c@example.com'), ''],
    ], PR259R_HEADER))));
    $a = Client::query()->where('email', 'a@example.com')->sole();
    app(CreditLedgerService::class)->post($a->id, Money::of(100, 'NPR'), CreditReason::Manual, description: 'moved');
    ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([
        [...ClientCsv::row('1', 'a@example.com'), '150'],
        [...ClientCsv::row('2', 'b@example.com'), '250'],
    ], PR259R_HEADER), ['source_id' => $first->migration_source_id]), ['on_match' => 'update']));

    $counts = [];
    Event::assertDispatched(MigrationRunCompleted::class, function (MigrationRunCompleted $event) use (&$counts): bool {
        $counts[] = $event->counts;

        return true;
    });

    expect($counts[0]['client']['created'])->toBe(3)
        ->and($counts[0]['credit_balance'])->toBe(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0])
        ->and($counts[1]['credit_balance']['updated'])->toBe(1);
});

test('N3 recheck: a credit in another currency than the matched client is a row error at check and posts nothing', function () {
    Currency::factory()->create(['code' => 'USD', 'is_default' => false]);
    $client = Client::factory()->create(['email' => 'asha@example.com', 'first_name' => 'Asha', 'last_name' => 'Rai', 'country' => 'NP', 'currency' => 'NPR']);

    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([[...ClientCsv::row('1', 'asha@example.com', currency: 'USD'), '500']], PR259R_HEADER)), ['on_match' => 'update']);
    $record = $run->records()->sole();

    expect($record->status)->toBe('error')->and($record->decision)->toBeNull()
        ->and(array_column($record->problems, 'code'))->toContain('credit_currency')
        ->and(CreditLedgerEntry::query()->where('client_id', $client->id)->count())->toBe(0);
});

test('CheckRun dry run: no client row lock and no ledger write', function () {
    $first = ClientCsv::apply(pr259rRun('1,000.00'));
    $before = CreditLedgerEntry::query()->count();
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = strtolower($query->sql);
    });

    $second = pr259rRun('1,500.00', $first);

    $clientLocks = array_filter($sql, fn (string $q): bool => str_contains($q, 'from "clients"') && str_contains($q, 'for update'));
    $ledgerWrites = array_filter($sql, fn (string $q): bool => preg_match('/^(insert into|update|delete from) "credit_ledger"/', $q) === 1);

    expect($second->records()->sole()->changes)->toBe(['credit_balance'])
        ->and($clientLocks)->toBe([])->and($ledgerWrites)->toBe([])
        ->and(CreditLedgerEntry::query()->count())->toBe($before);
});

test('N7 recheck: a GSTIN with state code 97 or 99 is not invalid', function (string $gstin) {
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([[...ClientCsv::row('1', 'gst@example.com', country: 'IN'), $gstin, 'KA']], [...ClientCsv::HEADER, 'Tax ID', 'State'])));

    expect(array_column($run->records()->sole()->problems ?? [], 'code'))->not->toContain('gstin_invalid');
})->with(['97ABCDE1234F1Z5', '99ABCDE1234F1Z5']);

test('R-B2 recheck: a client the import created without a balance keeps its credit when a later run brings one', function () {
    $first = ClientCsv::apply(pr259rRun(''));
    $client = Client::query()->where('email', 'asha@example.com')->sole();

    $second = pr259rRun('500.00', $first);
    $checked = $second->records()->sole();
    ClientCsv::apply($second);

    expect($checked->decision)->toBe('unchanged')
        ->and(array_column($checked->problems, 'code'))->toContain('credit_kept')
        ->and(pr259rImports($client))->toBe([]);
});

test('A-B2 recheck: an update that changes the client and the balance stores both outcomes and both snapshots', function () {
    $first = ClientCsv::apply(pr259rRun('1,000.00'));
    $client = Client::query()->where('email', 'asha@example.com')->sole();

    $second = ClientCsv::check(ClientCsv::upload(ClientCsv::file([[...ClientCsv::row('1', 'asha@example.com', last: 'Changed'), '1,200.00']], PR259R_HEADER),
        ['source_id' => $first->migration_source_id]), ['on_match' => 'update']);
    $record = ClientCsv::apply($second)->records()->sole();
    $maps = MigrationIdMap::query()->where('migration_source_id', $first->migration_source_id)->pluck('last_run_id', 'entity')->all();

    expect($record->outcome)->toBe('updated')
        ->and($record->changes)->toBe(['last_name', 'credit_balance'])
        ->and($record->entities['client'])->toBe(['action' => 'updated', 'target_id' => $client->id, 'changes' => ['last_name']])
        ->and($record->entities['credit_balance'])->toBe(['action' => 'updated', 'target_id' => $client->id, 'changes' => ['credit_balance']])
        ->and($record->before)->toBe(['client' => ['last_name' => 'Rai'], 'credit_balance' => ['credit_balance' => 100000]])
        ->and($maps)->toBe(['client' => $second->id, 'credit_balance' => $second->id])
        ->and(pr259rImports($client))->toBe([100000, 20000]);
});
