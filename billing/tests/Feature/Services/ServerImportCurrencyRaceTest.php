<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\Services\ServicesTestKit;

require_once __DIR__.'/../Billing/Support/race.php';

/**
 * #239: a confirmed server account import copies the reviewed client's currency into the new service. A currency change
 * at the same instant must never leave the client with a service priced in its old currency: the import reads the
 * client again under a share lock, and either the change waits for the service and is refused, or the account is
 * reported as not imported.
 */
const RACE_IMPORT_EMAIL = 'import-race@example.test';

/**
 * An active NPR client with no records and an importing list of one account reviewed for it at NPR 1,200 a month.
 */
function seedImportRace(): void
{
    Currency::factory()->npr()->create();
    $client = Client::factory()->create(['email' => RACE_IMPORT_EMAIL, 'currency' => 'NPR', 'status' => 'active']);
    $server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1']);
    ServerGroup::factory()->create()->servers()->attach($server, ['sort_order' => 0]);
    $account = ['username' => 'nepal', 'domain' => 'nepal.test', 'plan' => 'root_basic', 'owner' => 'root', 'email' => RACE_IMPORT_EMAIL, 'ip' => null,
        'suspended' => false, 'suspend_reason' => null, 'created_at' => '2025-03-15T00:00:00+00:00', 'disk_used_mb' => 1, 'disk_limit_mb' => 10,
        'category' => 'proposed', 'client_id' => $client->id, 'product_id' => 21, 'service_id' => null, 'note' => null, 'differences' => []];
    importWithAccounts([$account], ['server_id' => $server->id, 'status' => ServerAccountImport::IMPORTING, 'account_count' => 1, 'missing' => [],
        'connection_user' => 'root', 'fetched_at' => now(), 'expires_at' => now()->addDay(), 'reviewed' => ['digest' => 'race', 'staff_id' => null, 'rows' => [[
            'username' => 'nepal', 'client_id' => $client->id, 'product_id' => 21, 'cycle' => 'monthly', 'next_due_date' => '2026-11-15', 'anchor_day' => 15, 'amount' => 1200, 'currency' => 'NPR',
        ]]]]);
}

test('a currency change between the unlocked check and the insert is caught by the re-read under lock', function () {
    seedImportRace();
    $kit = ServicesTestKit::bind();
    $import = ServerAccountImport::query()->with('server')->sole();
    $kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200], driver: 'cpanel', serverGroupId: (int) $import->server->groups()->value('server_groups.id'));
    // The change commits right after the first (unlocked) read of the client, before the import takes its lock.
    $changed = false;
    Client::retrieved(function (Client $client) use (&$changed): void {
        if (! $changed) {
            $changed = true;
            DB::table('clients')->where('id', $client->id)->update(['currency' => 'USD']);
        }
    });

    [$line] = app(ImportServerAccounts::class)->handle($import->server, $import, null);

    expect($changed)->toBeTrue()
        ->and($line)->toMatchArray(['imported' => false, 'service_id' => null,
            'message' => 'The client\'s currency changed from NPR to USD since the review, and the account was priced in NPR. Review the account again.'])
        ->and(Service::query()->exists())->toBeFalse()
        ->and(DB::table('server_account_import_report_lines')->where('server_account_import_id', $import->id)->sole())->imported->toBeFalsy()
        ->and($import->fresh()->not_imported_count)->toBe(1);
});

describe('a currency change racing a server account import (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    test('a change that locked the client first wins, and the account waiting for it is not imported', function () {
        $this->useSeededDatabase('import_race_change_first', seedImportRace(...));
        $client = Client::query()->where('email', RACE_IMPORT_EMAIL)->sole();
        $database = DB::connection()->getDatabaseName();
        $importId = (string) ServerAccountImport::query()->value('id');

        // "change" holds the client lock for 1.5 s after its check; "import" starts 0.4 s later and reads NPR unlocked.
        $results = runRaceWorkers([
            'change' => ['currency', $database, (string) $client->id, 'USD', '0', '1500'],
            'import' => ['server-import', $database, $importId, '0', '400', '0'],
        ]);

        expect($results['change']['status'])->toBe('changed')
            ->and($results['import']['status'])->toBe('not_imported')
            ->and($results['import']['message'])->toBe('The client\'s currency changed from NPR to USD since the review, and the account was priced in NPR. Review the account again.')
            ->and($client->fresh()->currency)->toBe('USD')
            ->and(Service::query()->where('client_id', $client->id)->exists())->toBeFalse();
    });

    test('an import that locked the client first makes the change wait for the service, then refuses the change', function () {
        $this->useSeededDatabase('import_race_import_first', seedImportRace(...));
        $client = Client::query()->where('email', RACE_IMPORT_EMAIL)->sole();
        $database = DB::connection()->getDatabaseName();
        $importId = (string) ServerAccountImport::query()->value('id');

        // "import" pauses 1.5 s after its share lock, before the service insert; "change" starts 0.4 s later and would
        // find no service without the lock.
        $results = runRaceWorkers([
            'import' => ['server-import', $database, $importId, '0', '0', '1500'],
            'change' => ['currency', $database, (string) $client->id, 'USD', '400', '0'],
        ]);

        expect($results['import']['status'])->toBe('imported')
            ->and($results['import']['service_currency'])->toBe('NPR')
            ->and($results['change']['status'])->toBe('refused')
            ->and($results['change']['ended'])->toBeGreaterThanOrEqual($results['import']['ended'] - 0.05)
            ->and($client->fresh()->currency)->toBe('NPR');
    });
});
