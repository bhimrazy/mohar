<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\FetchAccountsForImport;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Jobs\ImportReviewedAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\AccountImportMatcher;
use App\Modules\Services\Support\AccountList;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Import accounts storage (#103, #136, #134, #135, #149): a fetched list is one row per account, so a page view or an
 * import chunk decrypts only the accounts it shows or creates; a report keeps one line per account and outlives its
 * list's expiry while the import runs.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200], driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_basic']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    actingAsStaff();
    confirmPassword();
});

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function stoAccount(string $username, array $extra = []): array
{
    return [...['username' => $username, 'domain' => "{$username}.example.test", 'plan' => 'root_basic', 'owner' => 'root', 'email' => "{$username}@example.test", 'ip' => null,
        'suspended' => false, 'suspend_reason' => null, 'created_at' => '2025-03-15T00:00:00+00:00', 'disk_used_mb' => 1, 'disk_limit_mb' => 10,
        'category' => 'unmatched', 'client_id' => null, 'product_id' => null, 'service_id' => null, 'note' => null, 'differences' => []], ...$extra];
}

/**
 * A ready list of the given accounts for the test's server.
 *
 * @param  list<array<string, mixed>>  $accounts
 * @param  array<string, mixed>  $attributes
 */
function stoList(Server $server, array $accounts, array $attributes = []): ServerAccountImport
{
    $counts = array_count_values(array_column($accounts, 'category'));

    return importWithAccounts($accounts, ['server_id' => $server->id, 'status' => 'ready', 'account_count' => count($accounts), 'missing' => [],
        'proposed_count' => $counts['proposed'] ?? 0, 'unmatched_count' => $counts['unmatched'] ?? 0, 'reseller_count' => $counts['reseller'] ?? 0,
        'connection_user' => 'root', 'fetched_at' => now(), 'expires_at' => now()->addDay(), ...$attributes]);
}

/** Make an account's payload unreadable: any code that decrypts it fails. */
function stoPoison(array $usernames): void
{
    DB::table('server_account_import_accounts')->whereIn('username', $usernames)->update(['payload' => 'not-decryptable']);
}

/**
 * Confirm the review of a ready list; the chunks are left for the test to run.
 */
function stoConfirm(Server $server): ServerAccountImport
{
    Queue::fake([ImportReviewedAccounts::class]);
    $digest = test()->get(route('admin.services.servers.import.review', $server))->inertiaProps('digest');
    test()->post(route('admin.services.servers.import.apply', $server), ['digest' => $digest])->assertSessionHasNoErrors();

    return ServerAccountImport::query()->where('server_id', $server->id)->sole();
}

test('the page filters, searches and pages on plain columns and decrypts only the accounts it shows', function () {
    stoList($this->server, [
        stoAccount('alpha', ['category' => 'proposed', 'client_id' => $this->anna->id, 'product_id' => 21, 'email' => 'anna@alpha-books.com']),
        stoAccount('bravo', ['email' => 'victim@example.test']),
        stoAccount('charlie', ['domain' => 'shop.charlie.test']),
        stoAccount('delta', ['category' => 'reseller']),
    ]);
    stoPoison(['bravo', 'charlie', 'delta']);

    $this->get(route('admin.services.servers.import.show', ['server' => $this->server, 'tab' => 'proposed']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('rows.data.0.username', 'alpha')->has('rows.data', 1)->where('counts.unmatched', 2));

    stoPoison(['alpha']);
    $found = fn (string $term): array => array_column($this->get(route('admin.services.servers.import.show', ['server' => $this->server, 'search' => $term]))->inertiaProps('rows')['data'] ?? [], 'username');
    DB::table('server_account_import_accounts')->update(['payload' => Crypt::encryptString(json_encode(stoAccount('x')))]);

    expect($found('CHARL'))->toBe(['x'])
        ->and($found('shop.charlie'))->toBe(['x'])
        ->and($found('victim@example.test'))->toBe(['x'])
        ->and($found('victim@'))->toBe([])
        ->and($found('%'))->toBe([])
        ->and($found('_lpha'))->toBe([]);
});

test('Select all proposed and the ticked cycle read plain columns and the ticked accounts only', function () {
    $list = stoList($this->server, [
        stoAccount('alpha', ['category' => 'proposed', 'client_id' => $this->anna->id, 'product_id' => 21, 'email' => 'anna@alpha-books.com']),
        stoAccount('bravo', ['category' => 'proposed', 'client_id' => $this->anna->id, 'product_id' => 21, 'email' => 'anna@alpha-books.com']),
        stoAccount('charlie'),
    ]);
    stoPoison(['charlie']);

    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha', 'charlie', 'unknown'], 'include' => true])->assertSessionHasNoErrors();
    expect(array_keys($list->refresh()->selections))->toBe(['alpha', 'charlie']);

    $this->put(route('admin.services.servers.import.select', $this->server), ['proposed' => true])->assertSessionHasNoErrors();
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['charlie'], 'include' => false])->assertSessionHasNoErrors();

    expect(collect($list->refresh()->selections)->filter(fn (array $choice): bool => $choice['include'])->keys()->sort()->values()->all())->toBe(['alpha', 'bravo']);
});

test('an import chunk decrypts only the accounts it creates and the report keeps one line each', function () {
    $accounts = array_map(fn (string $username): array => stoAccount($username, ['category' => 'proposed', 'client_id' => $this->anna->id, 'product_id' => 21, 'email' => 'anna@alpha-books.com']), ['alpha', 'bravo', 'charlie', 'delta']);
    stoList($this->server, $accounts, ['selections' => ['alpha' => ['include' => true], 'bravo' => ['include' => true]]]);
    stoPoison(['charlie', 'delta']);
    $import = stoConfirm($this->server);
    $digest = $import->reviewed['digest'];

    // The chunk that runs twice (a retried job): nothing is created, reported or counted again.
    (new ImportReviewedAccounts($import->id, auth()->id(), 0, $digest))->handle(app(ImportServerAccounts::class));
    $import->refresh();
    $first = $import->only(['imported_count', 'not_imported_count']);
    app(ImportServerAccounts::class)->handle($this->server, $import->forceFill(['status' => ServerAccountImport::IMPORTING]), auth()->user());

    $import->refresh();
    expect($first)->toBe(['imported_count' => 2, 'not_imported_count' => 0])
        ->and($import->only(['imported_count', 'not_imported_count']))->toBe($first)
        ->and(DB::table('server_account_import_report_lines')->count())->toBe(2)
        ->and(Service::query()->count())->toBe(2)
        ->and($import->linked_count)->toBe(2);
    expect(fn () => DB::table('server_account_import_report_lines')->insert(['server_account_import_id' => $import->id, 'username' => 'alpha', 'imported' => false, 'message' => 'again', 'created_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a list the previous release stored whole is moved into rows when it is first read, once', function () {
    $import = ServerAccountImport::query()->create(['server_id' => $this->server->id, 'status' => 'ready', 'account_count' => 2, 'missing' => [], 'fetched_at' => now(), 'expires_at' => now()->addDay()]);
    DB::table('server_account_imports')->where('id', $import->id)->update(['accounts' => Crypt::encryptString(json_encode([stoAccount('alpha'), stoAccount('bravo')]))]);

    $this->get(route('admin.services.servers.import.show', $this->server))->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 2));
    $this->get(route('admin.services.servers.import.show', $this->server))->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 2));

    expect(DB::table('server_account_imports')->where('id', $import->id)->value('accounts'))->toBeNull()
        ->and(DB::table('server_account_import_accounts')->count())->toBe(2)
        ->and($import->accountRows()[1]['username'])->toBe('bravo');
});

test('an import that outlives its list is kept while it runs, and its report is kept for a day after it finishes', function () {
    $accounts = array_map(fn (string $username): array => stoAccount($username, ['category' => 'proposed', 'client_id' => $this->anna->id, 'product_id' => 21, 'email' => 'anna@alpha-books.com']), ['alpha', 'bravo']);
    stoList($this->server, $accounts, ['selections' => ['alpha' => ['include' => true], 'bravo' => ['include' => true]], 'expires_at' => now()->addMinutes(5)]);
    $import = stoConfirm($this->server);
    $digest = $import->reviewed['digest'];

    // Starting it extends the list past its own expiry, and a list still importing is never pruned.
    expect($import->expires_at->greaterThan(now()->addHours(23)))->toBeTrue();
    $import->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->travel(5)->minutes();
    $import->forceFill(['updated_at' => now()])->saveQuietly();

    expect(ServerAccountImport::pruneExpired())->toBe(0)
        ->and(FetchAccountsForImport::latest($this->server)?->id)->toBe($import->id);

    (new ImportReviewedAccounts($import->id, auth()->id(), 0, $digest))->handle(app(ImportServerAccounts::class));
    $import->refresh();

    expect($import->status)->toBe('ready')
        ->and($import->expires_at->greaterThan(now()->addHours(23)))->toBeTrue()
        ->and(DB::table('server_account_import_report_lines')->where('server_account_import_id', $import->id)->count())->toBe(2);
    $this->travel(25)->hours();
    expect(ServerAccountImport::pruneExpired())->toBe(1)
        ->and(DB::table('server_account_import_report_lines')->count())->toBe(0)
        ->and(DB::table('server_account_import_accounts')->count())->toBe(0);
});

test('an import whose worker died is pruned once it has expired and gone stale, and a run deletes a bounded number', function () {
    $dead = stoList($this->server, [stoAccount('alpha')], ['status' => ServerAccountImport::IMPORTING, 'expires_at' => now()->subMinute()]);
    $dead->forceFill(['updated_at' => now()->subMinutes(ServerAccountImport::STALE_MINUTES + 1)])->saveQuietly();
    foreach (range(1, 3) as $i) {
        stoList(Server::factory()->create(), [stoAccount("old{$i}")], ['expires_at' => now()->subHours($i)]);
    }

    expect(ServerAccountImport::pruneExpired(limit: 2))->toBe(2)
        ->and(ServerAccountImport::query()->count())->toBe(2)
        ->and(ServerAccountImport::pruneExpired(limit: 2))->toBe(2)
        ->and(ServerAccountImport::query()->count())->toBe(0);
});

test('a stopped import marks the accounts it did not reach, and the page reads that mark', function () {
    $accounts = array_map(fn (string $username): array => stoAccount($username, ['category' => 'proposed', 'client_id' => $this->anna->id, 'product_id' => 21, 'email' => 'anna@alpha-books.com']), ['alpha', 'bravo', 'charlie']);
    stoList($this->server, $accounts, ['selections' => array_fill_keys(['alpha', 'bravo', 'charlie'], ['include' => true])]);
    $import = stoConfirm($this->server);
    app(ImportServerAccounts::class)->handle($this->server, $import, auth()->user(), ['alpha']);
    app(ImportServerAccounts::class)->stop($this->server, $import->refresh(), auth()->user(), 'Stopped for the test.');

    $this->get(route('admin.services.servers.import.show', $this->server))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('import.report.not_reached', 2)
        ->where('import.report.not_imported', 2)
        ->where('import.report.lines.0.not_reached', true)
        ->where('import.report.lines.2.not_reached', false));
});

test('a fetch that replaces a list moves its choices and report and deletes its accounts', function () {
    $old = stoList($this->server, [stoAccount('alpha'), stoAccount('bravo', ['created_at' => '2024-01-01T00:00:00+00:00'])], ['selections' => ['alpha' => ['include' => true], 'bravo' => ['include' => true]]]);
    DB::table('server_account_import_report_lines')->insert(['server_account_import_id' => $old->id, 'username' => 'alpha', 'imported' => false, 'message' => 'Because.', 'created_at' => now()]);
    $old->forceFill(['imported_count' => 0, 'not_imported_count' => 1])->save();
    $new = ServerAccountImport::query()->create(['server_id' => $this->server->id, 'status' => 'fetching', 'selections' => $old->selections, 'expires_at' => now()->addDay()]);

    // bravo was re-created on the server since: its choice is dropped; alpha is the same account.
    $store = app(AccountImportMatcher::class)->store($new->load('server'), [
        Arr::except(stoAccount('alpha'), ['category', 'client_id', 'product_id', 'service_id', 'note', 'differences']),
        Arr::except(stoAccount('bravo', ['created_at' => '2026-02-02T00:00:00+00:00']), ['category', 'client_id', 'product_id', 'service_id', 'note', 'differences']),
    ], 'root');

    $new->refresh();
    expect($store)->toBe(2)
        ->and(array_keys($new->selections))->toBe(['alpha'])
        ->and($new->not_imported_count)->toBe(1)
        ->and(DB::table('server_account_import_report_lines')->where('server_account_import_id', $new->id)->count())->toBe(1)
        ->and(ServerAccountImport::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(DB::table('server_account_import_accounts')->where('server_account_import_id', $old->id)->count())->toBe(0)
        ->and(app(AccountList::class)->counts($new))->toBe(['unmatched' => 2]);
});

test('the report-line migration keeps the earliest line of a duplicated account, recounts the import and builds the unique index', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Rebuilds the index inside the test transaction; the PostgreSQL build is concurrent and cannot run in one.');
    }

    $import = stoList($this->server, [stoAccount('alpha'), stoAccount('bravo')], ['imported_count' => 2, 'not_imported_count' => 1]);
    DB::statement('drop index sair_lines_import_username_unique');
    $line = fn (string $username, bool $imported) => DB::table('server_account_import_report_lines')->insert(['server_account_import_id' => $import->id, 'username' => $username, 'imported' => $imported, 'message' => 'm', 'created_at' => now()]);
    $line('alpha', true);
    $line('bravo', true);
    $line('alpha', false);

    (require base_path('app/Modules/Services/Database/Migrations/2026_10_24_400750_add_outcome_and_unique_to_server_account_import_report_lines.php'))->up();

    expect(DB::table('server_account_import_report_lines')->orderBy('id')->get(['username', 'imported'])->map(fn ($row) => [$row->username, (bool) $row->imported])->all())->toBe([['alpha', true], ['bravo', true]])
        ->and($import->refresh()->only(['imported_count', 'not_imported_count']))->toBe(['imported_count' => 2, 'not_imported_count' => 0])
        ->and(fn () => $line('alpha', false))->toThrow(UniqueConstraintViolationException::class);
});
