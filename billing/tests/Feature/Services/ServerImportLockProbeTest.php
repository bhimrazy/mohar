<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\Services\ServicesTestKit;

require_once __DIR__.'/../Billing/Support/race.php';

/**
 * Review probes for PR #261 (#239), PostgreSQL only. The import's transaction must hold the client at FOR SHARE and
 * never stronger (no share-then-update upgrade on the client), and a checkout or invoice that locks the client
 * FOR UPDATE must wait for the import, never deadlock with it.
 */
const PROBE_IMPORT_EMAIL = 'import-probe@example.test';

/**
 * An active NPR client with no records and an importing list of one account reviewed for it at NPR 1,200 a month.
 */
function seedImportProbe(): void
{
    Currency::factory()->npr()->create();
    $client = Client::factory()->create(['email' => PROBE_IMPORT_EMAIL, 'currency' => 'NPR', 'status' => 'active']);
    $server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1']);
    ServerGroup::factory()->create()->servers()->attach($server, ['sort_order' => 0]);
    $account = ['username' => 'nepal', 'domain' => 'nepal.test', 'plan' => 'root_basic', 'owner' => 'root', 'email' => PROBE_IMPORT_EMAIL, 'ip' => null,
        'suspended' => false, 'suspend_reason' => null, 'created_at' => '2025-03-15T00:00:00+00:00', 'disk_used_mb' => 1, 'disk_limit_mb' => 10,
        'category' => 'proposed', 'client_id' => $client->id, 'product_id' => 21, 'service_id' => null, 'note' => null, 'differences' => []];
    ServerAccountImport::query()->create(['server_id' => $server->id, 'status' => ServerAccountImport::IMPORTING, 'accounts' => [$account], 'account_count' => 1, 'missing' => [],
        'connection_user' => 'root', 'fetched_at' => now(), 'expires_at' => now()->addDay(), 'reviewed' => ['digest' => 'race', 'staff_id' => null, 'rows' => [[
            'username' => 'nepal', 'client_id' => $client->id, 'product_id' => 21, 'cycle' => 'monthly', 'next_due_date' => '2026-11-15', 'anchor_day' => 15, 'amount' => 1200, 'currency' => 'NPR',
        ]]]]);
}

describe('PR #261 probes (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    test('the import holds the client FOR SHARE, never stronger, through the insert and the count', function () {
        $this->useSeededDatabase('import_probe_lock_strength', seedImportProbe(...));
        $kit = ServicesTestKit::bind();
        $import = ServerAccountImport::query()->with('server')->sole();
        $client = Client::query()->where('email', PROBE_IMPORT_EMAIL)->sole();
        $kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200], driver: 'cpanel', serverGroupId: (int) $import->server->groups()->value('server_groups.id'));

        // A second session on the same database, outside the test's transaction.
        $seeded = DB::connection()->getName();
        config(["database.connections.{$seeded}_probe" => config("database.connections.{$seeded}")]);
        $probe = DB::connection("{$seeded}_probe");
        $try = function (string $table, int $id, string $mode) use ($probe): string {
            try {
                $probe->transaction(fn () => $probe->select("select id from {$table} where id = ? {$mode} nowait", [$id]));

                return 'granted';
            } catch (QueryException $e) {
                return $e->getCode() === '55P03' ? 'blocked' : 'error '.$e->getCode();
            }
        };
        $seen = [];
        DB::listen(function (QueryExecuted $query) use ($seeded, $client, $import, $try, &$seen): void {
            if ($query->connectionName !== $seeded) {
                return;
            }

            $at = match (true) {
                str_starts_with($query->sql, 'insert into "services"') => 'service insert',
                str_starts_with($query->sql, 'update "server_account_imports"') => 'count increment',
                default => null,
            };

            if ($at !== null) {
                $seen[$at] = [
                    'client for share' => $try('clients', $client->id, 'for share'),
                    'client for no key update' => $try('clients', $client->id, 'for no key update'),
                    'import for key share' => $try('server_account_imports', $import->id, 'for key share'),
                ];
            }
        });

        [$line] = app(ImportServerAccounts::class)->handle($import->server, $import, null);

        expect($line['imported'])->toBeTrue()
            ->and($seen)->toBe([
                // FOR SHARE from another session is granted: this transaction holds nothing stronger on the client.
                'service insert' => ['client for share' => 'granted', 'client for no key update' => 'blocked', 'import for key share' => 'granted'],
                // The count locks the import row (FOR NO KEY UPDATE), not the client: still share on the client.
                'count increment' => ['client for share' => 'granted', 'client for no key update' => 'blocked', 'import for key share' => 'granted'],
            ]);
    });

    test('a checkout locking the client FOR UPDATE waits for the import and does not deadlock', function () {
        $this->useSeededDatabase('import_probe_checkout', seedImportProbe(...));
        $client = Client::query()->where('email', PROBE_IMPORT_EMAIL)->sole();
        $database = DB::connection()->getDatabaseName();
        $importId = (string) ServerAccountImport::query()->value('id');

        // "import" holds the share lock 1.5 s before the service insert; "checkout" starts 0.4 s later.
        $results = runRaceWorkers([
            'import' => ['server-import', $database, $importId, '0', '0', '1500'],
            'checkout' => ['checkout', $database, (string) $client->id, '1200', '400', '0'],
        ]);

        expect($results['import'])->not->toHaveKey('error')
            ->and($results['checkout'])->not->toHaveKey('error')
            ->and($results['import']['status'])->toBe('imported')
            ->and($results['checkout']['ended'])->toBeGreaterThanOrEqual($results['import']['ended'] - 0.05)
            ->and(Service::query()->where('client_id', $client->id)->value('currency'))->toBe('NPR');
    });

    test('an invoice issued with credit while the import holds the client does not deadlock', function () {
        $this->useSeededDatabase('import_probe_issue', seedImportProbe(...));
        $client = Client::query()->where('email', PROBE_IMPORT_EMAIL)->sole();
        $database = DB::connection()->getDatabaseName();
        $importId = (string) ServerAccountImport::query()->value('id');

        $results = runRaceWorkers([
            'import' => ['server-import', $database, $importId, '0', '0', '1500'],
            'issue' => ['issue', $database, (string) $client->id, '1200', '400', '0'],
        ]);

        expect($results['import'])->not->toHaveKey('error')
            ->and($results['issue'])->not->toHaveKey('error')
            ->and($results['import']['status'])->toBe('imported');
    });
});
