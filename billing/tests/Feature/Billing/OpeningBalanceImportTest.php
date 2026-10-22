<?php

use App\Contracts\Migration\ImportIdResolver;
use App\Contracts\Migration\ReversibleImporter;
use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportRecord;
use App\Enums\ImportAction;
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

function openingImporter(): ReversibleImporter
{
    $importer = app(ImportRegistry::class)->get('credit_balance');
    expect($importer)->toBeInstanceOf(ReversibleImporter::class);

    /** @var ReversibleImporter $importer */
    return $importer;
}

function openingRecord(Client $client, int $amount, ?string $currency = null): ImportRecord
{
    return new ImportRecord('credit_balance', 'csv', 'row-1', ['amount' => $amount, 'currency' => $currency ?? $client->currency], ['client_id' => $client->id]);
}

beforeEach(function () {
    Currency::factory()->npr()->create();
});

test('a run posts the opening balance once, a dry run writes nothing, and a credit in another currency is refused', function () {
    $client = billingClient(['currency' => 'NPR']);

    expect(openingImporter()->upsert(openingRecord($client, 50000), openingContext(dryRun: true), $client->id)->action)->toBe(ImportAction::Created)
        ->and(CreditLedgerEntry::query()->count())->toBe(0);

    $outcome = openingImporter()->upsert(openingRecord($client, 50000), openingContext(), $client->id);
    // A redelivered row of the same run posts nothing more.
    $again = openingImporter()->upsert(openingRecord($client, 50000), openingContext(), $client->id);
    $usd = openingImporter()->upsert(openingRecord($client, 50000, 'USD'), openingContext(8), $client->id);

    expect($outcome->action)->toBe(ImportAction::Created)->and($outcome->before)->toBe(['credit_balance' => 0])
        ->and($again->action)->toBe(ImportAction::Unchanged)
        ->and($usd->action)->toBe(ImportAction::Skipped)->and($usd->issues[0]->code)->toBe('credit_currency')
        ->and(CreditLedgerEntry::query()->where('client_id', $client->id)->get(['amount', 'reason', 'gateway', 'external_ref'])->toArray())
        ->toBe([['amount' => 50000, 'reason' => 'import', 'gateway' => 'import', 'external_ref' => "run:7:client:{$client->id}"]]);
});

test('undo takes back exactly what the run posted, once, and keeps a balance the client has used', function () {
    $kept = billingClient(['currency' => 'NPR']);
    $undone = billingClient(['currency' => 'NPR']);
    $ledger = app(CreditLedgerService::class);

    foreach ([$kept, $undone] as $client) {
        openingImporter()->upsert(openingRecord($client, 100000), openingContext(), $client->id);
    }

    $ledger->post($kept->id, Money::of(-100, 'NPR'), CreditReason::AppliedToInvoice);

    expect(openingImporter()->reverse($undone->id, openingContext())->action)->toBe(ImportAction::Updated)
        ->and(openingImporter()->reverse($undone->id, openingContext())->action)->toBe(ImportAction::Unchanged)
        ->and($ledger->balance($undone->id, 'NPR')->amount)->toBe(0)
        ->and(openingImporter()->reverse($kept->id, openingContext())->issues[0]->code)->toBe('credit_used')
        ->and($ledger->balance($kept->id, 'NPR')->amount)->toBe(99900)
        // Another run's undo finds nothing of its own to reverse.
        ->and(openingImporter()->reverse($kept->id, openingContext(99))->action)->toBe(ImportAction::Unchanged);
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
        openingImporter()->upsert(openingRecord($client, 100000), openingContext(), $client->id);
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
