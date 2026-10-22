<?php

use App\Contracts\Migration\ImportIdResolver;
use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportOutcome;
use App\Data\Migration\ImportRecord;
use App\Enums\ImportAction;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Import\OpeningBalanceImporter;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\Support\Pr283PreviousReleaseOpeningBalanceImporter;

require_once __DIR__.'/helpers.php';

/**
 * Re-review probes for PR #283 (fix round, head 0f668b33): undo across the deploy overlap in both directions, and the
 * migration re-run over entries that carry both the legacy pair and the key.
 */
beforeEach(function () {
    Currency::factory()->npr()->create();
});

function pr283rContext(int $runId): ImportContext
{
    return new ImportContext($runId, 1, 'csv', false, CarbonImmutable::now(), null, new class implements ImportIdResolver
    {
        public function targetId(string $entity, string $sourceId): ?int
        {
            return null;
        }
    }, ['source_name' => 'Old billing']);
}

function pr283rRecord(Client $client, int $amount, string $row = 'row-1'): ImportRecord
{
    return new ImportRecord('credit_balance', 'csv', $row, ['amount' => $amount, 'currency' => $client->currency], ['client_id' => $client->id]);
}

function pr283RecheckImporters(): array
{
    return ['new' => app(OpeningBalanceImporter::class), 'old' => app(Pr283PreviousReleaseOpeningBalanceImporter::class)];
}

function pr283RecheckBalance(Client $client): int
{
    return app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount;
}

test('recheck: undo across releases takes the opening balance back exactly once, in every order', function (string $poster, string $firstUndo, string $secondUndo) {
    $importers = pr283RecheckImporters();
    $client = billingClient(['currency' => 'NPR']);

    expect($importers[$poster]->upsert(pr283rRecord($client, 100000), pr283rContext(11), null)->action)->toBe(ImportAction::Created);

    $applied = new ImportOutcome($client->id, ImportAction::Created);

    expect($importers[$firstUndo]->revert(pr283rRecord($client, 100000), $applied, pr283rContext(11))->action)->toBe(ImportAction::Reverted)
        ->and($importers[$secondUndo]->revert(pr283rRecord($client, 100000), $applied, pr283rContext(11))->action)->toBe(ImportAction::Unchanged)
        ->and(pr283RecheckBalance($client))->toBe(0)
        ->and(CreditLedgerEntry::query()->where('client_id', $client->id)->where('reason', CreditReason::Import)->count())->toBe(2);
})->with([
    'new posts, old undoes, new retries' => ['new', 'old', 'new'],
    'new posts, new undoes, old retries' => ['new', 'new', 'old'],
    'old posts, new undoes, old retries' => ['old', 'new', 'old'],
    'old posts, old undoes, new retries' => ['old', 'old', 'new'],
]);

test('recheck: a re-run of migration 300630 over pair-and-key entries and pair-only entries is consistent', function () {
    $importers = pr283RecheckImporters();
    $a = billingClient(['currency' => 'NPR']);
    $b = billingClient(['currency' => 'NPR']);

    $importers['new']->upsert(pr283rRecord($a, 100000), pr283rContext(12), null);
    $importers['new']->revert(pr283rRecord($a, 100000), new ImportOutcome($a->id, ImportAction::Created), pr283rContext(12));
    $importers['old']->upsert(pr283rRecord($b, 70000), pr283rContext(12), null);

    $migration = require base_path('app/Modules/Billing/Database/Migrations/2026_10_22_300630_add_idempotency_key_to_credit_ledger.php');
    // In a test transaction PostgreSQL refuses the CONCURRENTLY index build (OnlineIndex guard), so there only the
    // repeatable part runs; the full up() re-run on PostgreSQL is checked with artisan outside the test suite.
    if (DB::connection()->getDriverName() === 'pgsql') {
        $migration->backfill();
        $migration->backfill();
    } else {
        $migration->up();
        $migration->up();
    }

    $rows = CreditLedgerEntry::query()->whereIn('client_id', [$a->id, $b->id])->orderBy('id')->get(['gateway', 'external_ref', 'idempotency_key'])->toArray();

    expect($rows)->toBe([
        ['gateway' => 'import', 'external_ref' => "run:12:client:{$a->id}", 'idempotency_key' => "import:run:12:client:{$a->id}"],
        ['gateway' => 'import', 'external_ref' => "undo:run:12:client:{$a->id}", 'idempotency_key' => "import:undo:run:12:client:{$a->id}"],
        ['gateway' => 'import', 'external_ref' => "run:12:client:{$b->id}", 'idempotency_key' => "import:run:12:client:{$b->id}"],
    ])
        // After the backfill the new release still sees the old-release entry once.
        ->and($importers['new']->upsert(pr283rRecord($b, 90000, 'row-2'), pr283rContext(12), $b->id)->action)->toBe(ImportAction::Unchanged)
        ->and(pr283RecheckBalance($b))->toBe(70000);
});
