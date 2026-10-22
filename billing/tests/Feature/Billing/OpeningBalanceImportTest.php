<?php

use App\Contracts\Migration\ImportIdResolver;
use App\Contracts\Migration\RevertsImport;
use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportOutcome;
use App\Data\Migration\ImportRecord;
use App\Enums\ImportAction;
use App\Enums\ImportSeverity;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Migration\ImportRegistry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

/**
 * Billing's `credit_balance` importer (client-import spec §6.12, §5.7): one entry per run and client, in the client's
 * currency, reversed exactly by undo while the balance covers it.
 */
function openingContext(int $runId = 7, bool $dryRun = false): ImportContext
{
    return new ImportContext($runId, 1, 'csv', $dryRun, CarbonImmutable::now(), null, new class implements ImportIdResolver
    {
        public function targetId(string $entity, string $sourceId): ?int
        {
            return null;
        }
    }, ['source_name' => 'Old billing']);
}

function openingImporter(): RevertsImport
{
    $importer = app(ImportRegistry::class)->get('credit_balance');
    expect($importer)->toBeInstanceOf(RevertsImport::class);

    /** @var RevertsImport $importer */
    return $importer;
}

/** Undo of what run $runId posted for the client, as Migration hands it back. */
function openingRevert(Client $client, int $runId = 7): ImportOutcome
{
    return openingImporter()->revert(openingRecord($client, 0), new ImportOutcome($client->id, ImportAction::Created, changes: ['credit_balance']), openingContext($runId));
}

function openingRecord(Client $client, int $amount, ?string $currency = null): ImportRecord
{
    return new ImportRecord('credit_balance', 'csv', 'row-1', ['amount' => $amount, 'currency' => $currency ?? $client->currency], ['client_id' => $client->id]);
}

beforeEach(function () {
    Currency::factory()->npr()->create();
});

test('a run posts a new client\'s opening balance once; a dry run writes nothing; another currency is an error; an existing client keeps its credit', function () {
    $client = billingClient(['currency' => 'NPR']);

    expect(openingImporter()->upsert(openingRecord($client, 50000), openingContext(dryRun: true), null)->action)->toBe(ImportAction::Created)
        ->and(CreditLedgerEntry::query()->count())->toBe(0);

    $outcome = openingImporter()->upsert(openingRecord($client, 50000), openingContext(), null);
    // A redelivered row of the same run posts nothing more.
    $again = openingImporter()->upsert(openingRecord($client, 50000), openingContext(), null);
    $usd = openingImporter()->upsert(openingRecord($client, 50000, 'USD'), openingContext(8), $client->id);
    $existing = billingClient(['currency' => 'NPR']);
    $kept = openingImporter()->upsert(openingRecord($existing, 50000), openingContext(), $existing->id);

    expect($outcome->action)->toBe(ImportAction::Created)->and($outcome->before)->toBe(['credit_balance' => 0])
        ->and($again->action)->toBe(ImportAction::Unchanged)
        ->and($usd->action)->toBe(ImportAction::Skipped)->and($usd->issues[0]->code)->toBe('credit_currency')->and($usd->issues[0]->severity)->toBe(ImportSeverity::Error)
        // A client that existed before the import, never imported into, keeps its credit (CTO rule, §6.12).
        ->and($kept->action)->toBe(ImportAction::Skipped)->and($kept->issues[0]->message)->toBe('Existing credit kept; post the opening balance manually if it is still owed.')
        ->and(CreditLedgerEntry::query()->where('client_id', $client->id)->get(['amount', 'reason', 'gateway', 'external_ref'])->toArray())
        ->toBe([['amount' => 50000, 'reason' => 'import', 'gateway' => 'import', 'external_ref' => "run:7:client:{$client->id}"]]);
});

test('undo takes back exactly what the run posted, once, and keeps a balance the client has used', function () {
    $kept = billingClient(['currency' => 'NPR']);
    $undone = billingClient(['currency' => 'NPR']);
    $ledger = app(CreditLedgerService::class);

    foreach ([$kept, $undone] as $client) {
        openingImporter()->upsert(openingRecord($client, 100000), openingContext(), null);
    }

    $ledger->post($kept->id, Money::of(-100, 'NPR'), CreditReason::AppliedToInvoice);

    expect(openingRevert($undone)->action)->toBe(ImportAction::Reverted)
        ->and(openingRevert($undone)->action)->toBe(ImportAction::Unchanged)
        ->and($ledger->balance($undone->id, 'NPR')->amount)->toBe(0)
        ->and(openingRevert($kept)->issues[0]->code)->toBe('credit_used')
        ->and($ledger->balance($kept->id, 'NPR')->amount)->toBe(99900)
        // Another run's undo finds nothing of its own to reverse.
        ->and(openingRevert($kept, 99)->action)->toBe(ImportAction::Unchanged);
});

describe('opening balances racing staff credit movements (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    /** Committed: an NPR client with a Rs 1,000 opening balance from run 7. */
    $seed = function (): void {
        Currency::factory()->npr()->create();
        $client = billingClient(['email' => 'opening-race@example.test', 'currency' => 'NPR']);
        openingImporter()->upsert(openingRecord($client, 100000), openingContext(), null);
    };

    test('an undo waiting for a staff debit sees it and keeps the balance', function () use ($seed) {
        $this->useSeededDatabase('opening_race_undo', $seed);
        $client = Client::query()->where('email', 'opening-race@example.test')->sole();

        // "debit" holds the client lock for 1.5 s before its row; "undo" starts 0.4 s later, before the debit commits.
        $results = runRaceWorkers([
            'debit' => ['manual', DB::connection()->getDatabaseName(), (string) $client->id, '-60000', '0', '1500'],
            'undo' => ['opening-undo', DB::connection()->getDatabaseName(), (string) $client->id, '0', '400', '0'],
        ]);

        expect($results['debit']['status'])->toBe('posted')
            ->and($results['undo']['status'])->toBe('skipped:credit_used')
            ->and($results['undo']['ended'])->toBeGreaterThanOrEqual($results['debit']['ended'] - 0.05)
            ->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(40000);
    });

    test('a later run waiting for a staff credit sees it and keeps the balance instead of posting the difference', function () use ($seed) {
        $this->useSeededDatabase('opening_race_rerun', $seed);
        $client = Client::query()->where('email', 'opening-race@example.test')->sole();

        $results = runRaceWorkers([
            'credit' => ['manual', DB::connection()->getDatabaseName(), (string) $client->id, '50000', '0', '1500'],
            'rerun' => ['opening', DB::connection()->getDatabaseName(), (string) $client->id, '120000', '400', '0'],
        ]);

        expect($results['credit']['status'])->toBe('posted')
            ->and($results['rerun']['status'])->toBe('skipped:credit_kept')
            ->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(150000);
    });
});
