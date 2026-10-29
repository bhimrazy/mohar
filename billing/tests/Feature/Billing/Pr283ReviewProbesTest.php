<?php

use App\Contracts\Migration\ImportIdResolver;
use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportRecord;
use App\Enums\ImportAction;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Import\OpeningBalanceImporter;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Migration\ImportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\Support\Pr283PreviousReleaseOpeningBalanceImporter;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

/**
 * Review board probes for PR #283 (credit ledger idempotency key). Failing tests are findings; passing ones pin what
 * the review verified.
 */
function pr283Context(int $runId): ImportContext
{
    return new ImportContext($runId, 1, 'csv', false, CarbonImmutable::now(), null, new class implements ImportIdResolver
    {
        public function targetId(string $entity, string $sourceId): ?int
        {
            return null;
        }

        public function targetIds(string $entity, array $sourceIds): array
        {
            return [];
        }
    }, ['source_name' => 'Old billing']);
}

function pr283Record(Client $client, int $amount, string $row = 'row-1'): ImportRecord
{
    return new ImportRecord('credit_balance', 'csv', $row, ['amount' => $amount, 'currency' => $client->currency], ['client_id' => $client->id]);
}

beforeEach(function () {
    Currency::factory()->npr()->create();
});

// B1 (deploy overlap): architecture.md "expand, then contract" rule 1 and 4: old workers drain while new ones start,
// and new code writes both the old and the new shape while it matters. This release writes the key only, so the
// previous release (which matches a run's entry by gateway `import` + external_ref only) cannot see an entry the new
// release posted for the same run and client, and posts the difference on top. Two rows of one run that resolve to the
// same client (an External ID mapped to the client, and the client's email on another row) are the case the
// once-per-run reference exists for.
test('B1: a previous-release worker does not post a second opening balance for a run the new release already posted', function () {
    $client = billingClient(['currency' => 'NPR']);
    $new = app(OpeningBalanceImporter::class);
    $old = app(Pr283PreviousReleaseOpeningBalanceImporter::class);

    expect($new->upsert(pr283Record($client, 100000), pr283Context(9), null)->action)->toBe(ImportAction::Created);

    $second = $old->upsert(pr283Record($client, 150000, 'row-2'), pr283Context(9), $client->id);

    expect($second->action)->toBe(ImportAction::Unchanged)
        ->and(CreditLedgerEntry::query()->where('client_id', $client->id)->where('reason', CreditReason::Import)->count())->toBe(1)
        ->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(100000);
});

// Verified: the same two rows on the new release alone, and old-then-new, post once.
test('verified: new release alone, and previous release then new release, post once per run and client', function () {
    $a = billingClient(['currency' => 'NPR']);
    $b = billingClient(['currency' => 'NPR']);
    $new = app(OpeningBalanceImporter::class);
    $old = app(Pr283PreviousReleaseOpeningBalanceImporter::class);

    $new->upsert(pr283Record($a, 100000), pr283Context(9), null);
    $old->upsert(pr283Record($b, 100000), pr283Context(9), null);

    expect($new->upsert(pr283Record($a, 150000, 'row-2'), pr283Context(9), $a->id)->action)->toBe(ImportAction::Unchanged)
        ->and($new->upsert(pr283Record($b, 150000, 'row-2'), pr283Context(9), $b->id)->action)->toBe(ImportAction::Unchanged)
        ->and(app(CreditLedgerService::class)->balance($a->id, 'NPR')->amount)->toBe(100000)
        ->and(app(CreditLedgerService::class)->balance($b->id, 'NPR')->amount)->toBe(100000);
});

describe('two workers posting the same opening balance at once (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    // Verified: two processes run the same run 8 for the same client together; the client lock serialises them and the
    // second finds the first one's idempotency key, so exactly one run-8 entry and no duplicate money.
    test('verified: exactly one credit row for the run and client, the other worker unchanged', function () {
        $this->useSeededDatabase('pr283_twin', function (): void {
            Currency::factory()->npr()->create();
            $client = billingClient(['email' => 'pr283-twin@example.test', 'currency' => 'NPR']);
            app(ImportRegistry::class)->get('credit_balance')->upsert(pr283Record($client, 100000), pr283Context(7), null);
        });
        $client = Client::query()->where('email', 'pr283-twin@example.test')->sole();
        $database = DB::connection()->getDatabaseName();

        $results = runRaceWorkers([
            'first' => ['opening', $database, (string) $client->id, '120000', '0', '0'],
            'second' => ['opening', $database, (string) $client->id, '120000', '0', '0'],
        ]);
        $statuses = [$results['first']['status'], $results['second']['status']];
        sort($statuses);

        expect($statuses)->toBe(['unchanged', 'updated'])
            ->and(CreditLedgerEntry::query()->where('client_id', $client->id)->where('idempotency_key', "import:run:8:client:{$client->id}")->count())->toBe(1)
            ->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(120000);
    });
});
