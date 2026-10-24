<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Import accounts, step 1 (cPanel spec §10, AC 25, 26 and 28): fetch the server's accounts and match them once, when
 * the fetch finishes. Nothing is created or changed. Permission, validation and redaction cases are in
 * Security/ServerImportSecurityTest.php; queue and housekeeping in ServerImportReviewProbesTest.php.
 */
beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $this->group = ServerGroup::factory()->create();
    $this->group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', driver: 'cpanel', serverGroupId: $this->group->id, driverConfig: ['package' => 'root_basic']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com']);

    $accounts = [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']];
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => $accounts]])]);
    actingAsStaff();
    confirmPassword();
});

/**
 * Fetch the server's accounts (the queue runs synchronously in tests) and return the stored import.
 */
function fetchAccounts(Server $server): ServerAccountImport
{
    test()->post(route('admin.services.servers.import.fetch', $server))->assertSessionHasNoErrors()->assertRedirect();

    return ServerAccountImport::query()->where('server_id', $server->id)->sole();
}

/**
 * @return array<string, array<string, mixed>>
 */
function storedRows(ServerAccountImport $import): array
{
    return collect($import->accountRows())->keyBy('username')->all();
}

test('each account is matched once when the fetch finishes, and the counts are stored with the list', function () {
    $clients = Client::query()->count();

    $import = fetchAccounts($this->server);
    $rows = storedRows($import);

    expect($rows['alpha'])->toMatchArray(['category' => 'proposed', 'client_id' => $this->anna->id, 'product_id' => 21, 'note' => null])
        ->and($rows['bravo']['category'])->toBe('reseller')
        ->and($rows['charlie'])->toMatchArray(['category' => 'unmatched', 'client_id' => null])
        ->and($rows['charlie']['note'])->toContain('no valid contact email')
        ->and($import->only(['status', 'account_count', 'proposed_count', 'unmatched_count', 'reseller_count', 'linked_count', 'missing']))
        ->toBe(['status' => 'ready', 'account_count' => 3, 'proposed_count' => 1, 'unmatched_count' => 1, 'reseller_count' => 1, 'linked_count' => 0, 'missing' => []])
        ->and(Client::query()->count())->toBe($clients)
        ->and(Service::query()->count())->toBe(0);
});

test('a package two products use leaves the account unmatched with the reason', function () {
    $this->kit->priceBook->addProduct(22, 'Basic hosting (old)', driver: 'cpanel', serverGroupId: null, driverConfig: ['package' => 'root_basic']);
    $this->server->update(['is_default' => true]);

    expect(storedRows(fetchAccounts($this->server))['alpha'])
        ->category->toBe('unmatched')
        ->product_id->toBeNull()
        ->note->toBe('2 products use the package root_basic.');
});

test('an account a live service holds is linked with its differences, and a live service missing on the server is flagged and left alone', function () {
    $linked = Service::factory()->create(['server_id' => $this->server->id, 'username' => 'alpha', 'domain' => 'alpha-books.com', 'status' => ServiceStatus::Suspended, 'product_id' => 21, 'driver_data' => ['ip' => '203.0.113.99']]);
    $missing = Service::factory()->create(['server_id' => $this->server->id, 'username' => 'delta', 'status' => ServiceStatus::Active]);
    Service::factory()->create(['server_id' => $this->server->id, 'username' => 'echo', 'status' => ServiceStatus::Terminated]);

    $import = fetchAccounts($this->server);

    expect(storedRows($import)['alpha'])
        ->category->toBe('linked')
        ->service_id->toBe($linked->id)
        ->differences->toBe(['Suspended here, not on the server.', 'IP on the server: 203.0.113.20 (here: 203.0.113.99).'])
        ->and($import->missing)->toBe([$missing->id])
        ->and($missing->refresh()->status)->toBe(ServiceStatus::Active)
        ->and(ModuleCommand::query()->count())->toBe(0);
});

test('matching a large server reads the live services once and the clients in batches of 500', function () {
    $accounts = [];

    foreach (range(1, 2000) as $i) {
        $accounts[] = ['user' => "u{$i}", 'domain' => "d{$i}.test", 'plan' => 'root_basic', 'owner' => 'root', 'email' => "c{$i}@example.test", 'suspended' => 0, 'unix_startdate' => 1700000000];
    }

    $template = Service::factory()->create(['server_id' => $this->server->id, 'username' => 'seed0', 'status' => ServiceStatus::Active])->getAttributes();
    unset($template['id']);
    collect(range(1, 1000))->chunk(200)->each(fn ($chunk) => DB::table('services')->insert($chunk->map(fn (int $i): array => [...$template, 'username' => "u{$i}"])->all()));
    // WHM pages the list 500 at a time (api.chunk.start is 1-based).
    Whm::fake(['listaccts' => function (Request $request) use ($accounts) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $data = $request->data() + $query;

        return Http::response(Whm::fixture('listaccts_empty', ['data' => ['acct' => array_slice($accounts, (int) $data['api.chunk.start'] - 1, (int) $data['api.chunk.size'])]]));
    }]);

    $queries = ['services' => 0, 'clients' => 0];
    DB::listen(function ($query) use (&$queries): void {
        foreach (array_keys($queries) as $table) {
            $queries[$table] += (int) str_contains($query->sql, "from \"{$table}\"");
        }
    });
    memory_reset_peak_usage();
    $before = memory_get_usage();

    $import = fetchAccounts($this->server);

    expect($import->only(['account_count', 'linked_count', 'unmatched_count']))->toBe(['account_count' => 2000, 'linked_count' => 1000, 'unmatched_count' => 1000])
        ->and($queries['services'])->toBe(1)
        ->and($queries['clients'])->toBe(4) // one per 500 emails
        ->and((memory_get_peak_usage() - $before) / 1048576)->toBeLessThan(48.0);
});

test('a fetch already running is not started twice, and an expired list is deleted when read', function () {
    ServerAccountImport::query()->create(['server_id' => $this->server->id, 'status' => 'fetching', 'expires_at' => now()->addDay()]);

    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasErrors('import');

    ServerAccountImport::query()->update(['status' => 'ready', 'expires_at' => now()->subMinute()]);
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();

    expect(ServerAccountImport::query()->sole()->status)->toBe('ready');
});

test('fetching needs a recent password', function () {
    actingAsStaff();
    $this->withSession(['auth.password_confirmed_at' => 0]);

    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasErrors('password_confirmation');

    expect(ServerAccountImport::query()->count())->toBe(0);
});
