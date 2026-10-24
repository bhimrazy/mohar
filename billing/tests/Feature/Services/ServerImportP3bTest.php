<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Actions\AcceptServerValues;
use App\Modules\Services\Actions\FetchAccountsForImport;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Jobs\FetchServerAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ProvisioningSnapshots;
use App\Modules\Services\Support\AccountImportMatcher;
use App\Modules\Services\Support\AccountList;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Server import features (cPanel spec §10.2, #139 #140 #143 #141) and the storage follow-ups of #453: accept the
 * server's values into a linked service, ignore accounts, offer only priced cycles, a prune capped by rows, one UPDATE
 * to link a chunk, Match again that streams, and a message for a stored list that cannot be read.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200, 'annually' => 12000], driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_basic']);
    $this->kit->priceBook->addProduct(22, 'Monthly only', ['monthly' => 900], driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_plus']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    actingAsStaff();
    confirmPassword();
});

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function p3bAccount(string $username, array $extra = []): array
{
    return [...['username' => $username, 'domain' => "{$username}.example.test", 'plan' => 'root_basic', 'owner' => 'root', 'email' => "{$username}@example.test", 'ip' => null,
        'suspended' => false, 'suspend_reason' => null, 'created_at' => '2025-03-15T00:00:00+00:00', 'disk_used_mb' => 1, 'disk_limit_mb' => 10,
        'category' => 'unmatched', 'client_id' => null, 'product_id' => null, 'service_id' => null, 'note' => null, 'differences' => []], ...$extra];
}

/**
 * @param  list<array<string, mixed>>  $accounts
 * @param  array<string, mixed>  $attributes
 */
function p3bList(Server $server, array $accounts, array $attributes = []): ServerAccountImport
{
    $counts = array_count_values(array_column($accounts, 'category'));

    return importWithAccounts($accounts, ['server_id' => $server->id, 'status' => 'ready', 'account_count' => count($accounts), 'missing' => [],
        'proposed_count' => $counts['proposed'] ?? 0, 'unmatched_count' => $counts['unmatched'] ?? 0, 'reseller_count' => $counts['reseller'] ?? 0, 'linked_count' => $counts['linked'] ?? 0,
        'connection_user' => 'root', 'fetched_at' => now(), 'expires_at' => now()->addDay(), ...$attributes]);
}

/**
 * @return array<string, array<string, mixed>>
 */
function p3bRows(Server $server, array $query = []): array
{
    return collect(test()->get(route('admin.services.servers.import.show', [$server, ...$query]))->assertOk()->inertiaProps('rows.data'))->keyBy('username')->all();
}

/** A live service holding "alpha" that differs from the list's alpha in domain, IP and status. */
function p3bLinkedService(Server $server, Client $client): Service
{
    return Service::factory()->create(['server_id' => $server->id, 'client_id' => $client->id, 'username' => 'alpha', 'domain' => 'old.example.test', 'status' => ServiceStatus::Active, 'driver_data' => ['ip' => '10.0.0.1']]);
}

test('staff accept the domain, IP and suspension from the server into a linked service, one change at a time', function () {
    $service = p3bLinkedService($this->server, $this->anna);
    p3bList($this->server, [p3bAccount('alpha', ['category' => 'linked', 'ip' => '10.0.0.9', 'suspended' => true, 'suspend_reason' => 'bandwidth'])]);

    $accept = p3bRows($this->server)['alpha']['accept'];

    expect(array_column($accept, 'field'))->toBe(['domain', 'ip', 'status']);

    foreach ($accept as $change) {
        $this->post(route('admin.services.servers.import.accept', $this->server), ['username' => 'alpha', 'fields' => [$change['field']], 'digest' => $change['digest']])->assertSessionHasNoErrors();
    }

    $service->refresh();
    expect($service->domain)->toBe('alpha.example.test')
        ->and($service->driver_data['ip'])->toBe('10.0.0.9')
        ->and($service->status)->toBe(ServiceStatus::Suspended)
        ->and($service->suspension_reason)->toContain('suspended there: bandwidth')
        ->and(p3bRows($this->server)['alpha']['accept'])->toBe([]);
});

test('an accept is refused when the service or the account changed since the page showed it', function () {
    $service = p3bLinkedService($this->server, $this->anna);
    p3bList($this->server, [p3bAccount('alpha', ['category' => 'linked'])]);
    $change = p3bRows($this->server)['alpha']['accept'][0];
    $service->update(['domain' => 'someone-edited-this.test']);

    $this->post(route('admin.services.servers.import.accept', $this->server), ['username' => 'alpha', 'fields' => ['domain'], 'digest' => $change['digest']])
        ->assertSessionHasErrors('import');
    $this->post(route('admin.services.servers.import.accept', $this->server), ['username' => 'alpha', 'fields' => ['domain'], 'digest' => str_repeat('a', 64)])
        ->assertSessionHasErrors('import');

    expect($service->refresh()->domain)->toBe('someone-edited-this.test');
});

test('accepting needs the permission to manage services and sends nothing to the server', function () {
    $service = p3bLinkedService($this->server, $this->anna);
    p3bList($this->server, [p3bAccount('alpha', ['category' => 'linked'])]);
    $digest = AcceptServerValues::digest($service->id, [['field' => 'domain', 'label' => 'Domain', 'server' => 'alpha.example.test', 'local' => 'old.example.test']]);

    actingAsStaff(['services.servers.import', 'services.servers.view']);
    confirmPassword();
    expect(p3bRows($this->server)['alpha']['accept'])->toBe([]);
    $this->post(route('admin.services.servers.import.accept', $this->server), ['username' => 'alpha', 'fields' => ['domain'], 'digest' => $digest])->assertForbidden();

    expect($service->refresh()->domain)->toBe('old.example.test');
});

test('ignoring removes an account from the work list, it cannot be ticked, and it stays ignored in the next fetch', function () {
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['charlie'], 'include' => true])->assertSessionHasNoErrors();

    $this->put(route('admin.services.servers.import.ignore', $this->server), ['usernames' => ['charlie', 'alpha'], 'ignored' => true])->assertSessionHasNoErrors();

    $this->get(route('admin.services.servers.import.show', $this->server))->assertInertia(fn (Assert $page) => $page
        ->where('counts', ['all' => 3, 'proposed' => 0, 'unmatched' => 0, 'reseller' => 1, 'linked' => 0, 'ignored' => 2, 'missing' => 0])
        ->where('included', 0));
    expect(array_keys(p3bRows($this->server, ['tab' => 'ignored'])))->toBe(['alpha', 'charlie']);

    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['charlie'], 'include' => true]);
    $this->put(route('admin.services.servers.import.select', $this->server), ['proposed' => true]);
    expect(ServerAccountImport::query()->sole()->selections)->toBeEmpty();

    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    expect(array_keys(p3bRows($this->server, ['tab' => 'ignored'])))->toBe(['alpha', 'charlie'])
        ->and(ServerAccountImport::query()->sole()->proposed_count)->toBe(0);

    $this->put(route('admin.services.servers.import.ignore', $this->server), ['usernames' => ['alpha'], 'ignored' => false])->assertSessionHasNoErrors();
    expect(p3bRows($this->server)['alpha']['category'])->toBe('proposed')
        ->and(DB::table('server_import_ignores')->pluck('username')->all())->toBe(['charlie']);
});

test('an account a service holds cannot be ignored', function () {
    Service::factory()->create(['server_id' => $this->server->id, 'client_id' => $this->anna->id, 'username' => 'alpha', 'status' => ServiceStatus::Active]);
    p3bList($this->server, [p3bAccount('alpha', ['category' => 'linked'])]);

    $this->put(route('admin.services.servers.import.ignore', $this->server), ['usernames' => ['alpha'], 'ignored' => true])->assertSessionHasNoErrors();

    expect(DB::table('server_import_ignores')->count())->toBe(0)
        ->and(p3bRows($this->server)['alpha']['category'])->toBe('linked');
});

test('the choice dialog is given only the cycles a product is priced for, and the review only the cycles its rows can use', function () {
    p3bList($this->server, [p3bAccount('alpha', ['category' => 'proposed', 'client_id' => $this->anna->id, 'product_id' => 22, 'plan' => 'root_plus', 'email' => 'anna@alpha-books.com'])]);

    $this->get(route('admin.services.servers.import.show', $this->server))->assertInertia(fn (Assert $page) => $page
        ->where('pricedCycles.21.USD', ['monthly', 'annually'])
        ->where('pricedCycles.22.USD', ['monthly']));

    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true]);
    $this->get(route('admin.services.servers.import.review', $this->server))->assertInertia(fn (Assert $page) => $page
        ->where('cycles', [['value' => 'monthly', 'label' => 'Monthly']]));
});

test('the prune is capped by account rows as well as lists, and always removes the oldest list', function () {
    $list = fn (int $hours, int $accounts): ServerAccountImport => ServerAccountImport::query()->create(['server_id' => $this->server->id, 'status' => 'ready', 'account_count' => $accounts, 'expires_at' => now()->subHours($hours)]);
    $huge = $list(5, 60000);
    $first = $list(4, 20000);
    $second = $list(3, 20000);
    $third = $list(2, 20000);

    expect(ServerAccountImport::pruneExpired())->toBe(1)
        ->and(ServerAccountImport::query()->whereKey($huge->id)->exists())->toBeFalse()
        ->and(ServerAccountImport::pruneExpired())->toBe(2)
        ->and(ServerAccountImport::query()->pluck('id')->all())->toBe([$third->id])
        ->and($first->id)->toBeLessThan($second->id);
});

test('linking a chunk of imported accounts is one UPDATE of the plain columns, not one per account', function () {
    $import = p3bList($this->server, array_map(fn (string $name): array => p3bAccount($name, ['category' => 'proposed']), ['a1', 'a2', 'a3', 'a4']));
    $updates = 0;
    DB::listen(function ($query) use (&$updates): void {
        $updates += str_starts_with($query->sql, 'update "server_account_import_accounts"') ? 1 : 0;
    });

    app(AccountList::class)->link($import, ['a1' => 11, 'a2' => 12, 'a3' => 13]);

    $rows = app(AccountList::class)->rows($import);
    expect($updates)->toBe(1)
        ->and($rows['a1'])->toMatchArray(['category' => 'linked', 'client_id' => null, 'differences' => []])
        ->and($rows['a4']['category'])->toBe('proposed')
        ->and($import->refresh()->account_count)->toBe(4);
});

test('Match again writes only the accounts whose answer changed, keeps ignored accounts ignored, and counts them', function () {
    $import = p3bList($this->server, [
        p3bAccount('alpha', ['email' => 'anna@alpha-books.com']),
        p3bAccount('bravo', ['email' => 'nobody@example.test', 'product_id' => 21, 'note' => 'No client has the email nobody@example.test.']),
        p3bAccount('charlie', ['email' => 'nobody2@example.test', 'product_id' => 21, 'note' => 'No client has the email nobody2@example.test.']),
    ], ['status' => 'matching', 'unmatched_count' => 3]);
    app(AccountList::class)->setIgnored($import, ['charlie'], true);
    // bravo and charlie are stored as a match would give them now; only alpha gained a client.
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (str_starts_with($query->sql, 'update "server_account_import_accounts"')) {
            $writes[] = $query->bindings;
        }
    });

    (new FetchServerAccounts($import->id, rematch: true))->handle(...p3bFetchDependencies());

    $import->refresh();
    expect($writes)->toHaveCount(1)
        ->and($import->status)->toBe('ready')
        ->and([$import->proposed_count, $import->unmatched_count, $import->account_count])->toBe([1, 1, 3])
        ->and(app(AccountList::class)->rows($import)['alpha'])->toMatchArray(['category' => 'proposed', 'client_id' => $this->anna->id, 'product_id' => 21])
        ->and(app(AccountList::class)->counts($import))->toEqual(['proposed' => 1, 'unmatched' => 1, 'ignored' => 1]);
});

test('a stored list that cannot be read is dropped once, reported once, and the page tells staff to fetch again', function () {
    Exceptions::fake();
    $import = ServerAccountImport::query()->create(['server_id' => $this->server->id, 'status' => 'ready', 'account_count' => 3, 'proposed_count' => 1, 'expires_at' => now()->addDay()]);
    DB::table('server_account_imports')->where('id', $import->id)->update(['accounts' => Crypt::encryptString('{not json')]);

    $page = fn () => $this->get(route('admin.services.servers.import.show', $this->server))->assertOk();

    $page()->assertInertia(fn (Assert $page) => $page->where('import.status', 'failed')->where('import.error', fn (string $error) => str_contains($error, 'Fetch the accounts again'))->where('import.account_count', 0));
    $page();

    Exceptions::assertReportedCount(1);
    expect(DB::table('server_account_imports')->where('id', $import->id)->value('accounts'))->toBeNull();
});

/**
 * @return list<object>
 */
function p3bFetchDependencies(): array
{
    return [app(ProvisioningDriverRegistry::class), app(ProvisioningSnapshots::class), app(Redactor::class), app(AccountImportMatcher::class), app(ActivityLogger::class)];
}

test('fetching again after a failed list starts clean', function () {
    ServerAccountImport::query()->create(['server_id' => $this->server->id, 'status' => 'failed', 'error' => 'x', 'expires_at' => now()->addDay()]);
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => Whm::fixture('listaccts_page1')['data']['acct']]])]);

    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();

    expect(FetchAccountsForImport::latest($this->server)->status)->toBe('ready');
});
