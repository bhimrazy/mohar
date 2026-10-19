<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Import accounts, step 1 (cPanel spec §10, AC 25, 26 and 28): fetch the server's accounts and show how each one
 * matches clients, products and services here. Nothing is created or changed.
 */
beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_basic']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com']);

    $accounts = [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']];
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => $accounts]])]);
});

function fetchAccounts(Server $server): void
{
    test()->post(route('admin.services.servers.import.fetch', $server))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.services.servers.import.show', $server));
}

/**
 * @return array<string, array<string, mixed>>
 */
function importRows(Server $server, string $tab = 'all'): array
{
    $rows = test()->get(route('admin.services.servers.import.show', [$server, 'tab' => $tab]))->assertOk()->inertiaProps('rows.data');

    return collect($rows)->keyBy('username')->all();
}

test('an account whose email and package match exactly one client and product is proposed with both', function () {
    actingAsStaff();
    confirmPassword();
    $clients = Client::query()->count();

    fetchAccounts($this->server);
    $rows = importRows($this->server);

    expect($rows['alpha']['category'])->toBe('proposed')
        ->and($rows['alpha']['client'])->toBe(['id' => $this->anna->id, 'name' => $this->anna->display_name])
        ->and($rows['alpha']['product'])->toBe(['id' => 21, 'name' => 'Basic hosting'])
        ->and($rows['charlie']['category'])->toBe('unmatched')
        ->and($rows['charlie']['client'])->toBeNull()
        ->and($rows['charlie']['note'])->toContain('no contact email')
        ->and(Client::query()->count())->toBe($clients)
        ->and(Service::query()->count())->toBe(0);

    $this->get(route('admin.services.servers.import.show', $this->server))->assertInertia(fn (Assert $page) => $page
        ->component('admin/services/servers/import')
        ->where('counts', ['all' => 3, 'missing' => 0, 'proposed' => 1, 'unmatched' => 1, 'reseller' => 1, 'linked' => 0])
        ->where('import.status', 'ready'));
});

test('a client email shared by two clients, or a package no product uses, leaves the account unmatched with the reason', function () {
    actingAsStaff();
    confirmPassword();
    $this->kit->priceBook->addProduct(22, 'Basic hosting (old)', driver: 'cpanel', serverGroupId: null, driverConfig: ['package' => 'root_basic']);
    $this->server->update(['is_default' => true]);

    fetchAccounts($this->server);

    expect(importRows($this->server)['alpha'])
        ->category->toBe('unmatched')
        ->product->toBeNull()
        ->note->toBe('2 products use the package root_basic.');
});

test('reseller-owned accounts on a root connection are listed apart', function () {
    actingAsStaff();
    confirmPassword();

    fetchAccounts($this->server);

    expect(array_keys(importRows($this->server, 'reseller')))->toBe(['bravo'])
        ->and(importRows($this->server, 'reseller')['bravo']['suspended'])->toBeTrue();
});

test('an account a live service holds is linked, and a live service missing on the server is flagged and left alone', function () {
    actingAsStaff();
    confirmPassword();
    $linked = Service::factory()->create(['server_id' => $this->server->id, 'username' => 'alpha', 'domain' => 'alpha-books.com', 'status' => ServiceStatus::Suspended, 'product_id' => 21]);
    $missing = Service::factory()->create(['server_id' => $this->server->id, 'username' => 'delta', 'status' => ServiceStatus::Active]);
    Service::factory()->create(['server_id' => $this->server->id, 'username' => 'echo', 'status' => ServiceStatus::Terminated]);

    fetchAccounts($this->server);

    expect(importRows($this->server)['alpha'])
        ->category->toBe('linked')
        ->service->id->toBe($linked->id)
        ->differences->toBe(['Suspended here, not on the server.'])
        ->and(array_column(importRows($this->server, 'missing'), 'service'))->toHaveCount(1)
        ->and(importRows($this->server, 'missing')['delta']['service']['id'])->toBe($missing->id)
        ->and($missing->refresh()->status)->toBe(ServiceStatus::Active)
        ->and(ModuleCommand::query()->count())->toBe(0);
});

test('the fetched list is encrypted at rest and the fetch is audited', function () {
    actingAsStaff();
    confirmPassword();

    fetchAccounts($this->server);

    expect((string) DB::table('server_account_imports')->value('accounts'))->not->toContain('alpha-books.com')
        ->and(ActivityLog::query()->where('subject_id', $this->server->id)->latest('id')->value('description'))->toBe('Accounts on server Shared 1 fetched for import');
});

test('an unreachable server ends the fetch with our message, never the credentials', function () {
    actingAsStaff();
    confirmPassword();
    Whm::fake(['listaccts' => Http::failedConnection('cURL error 7: Failed to connect to '.Whm::HOST)]);

    fetchAccounts($this->server);

    $import = ServerAccountImport::query()->sole();
    expect($import->status)->toBe('failed')
        ->and($import->error)->toStartWith('The accounts on Shared 1 could not be read:')
        ->and($import->error)->not->toContain(Whm::TOKEN);
});

test('a fetch already running is not started twice, and an expired list is no longer shown', function () {
    actingAsStaff();
    confirmPassword();
    ServerAccountImport::query()->create(['server_id' => $this->server->id, 'status' => 'fetching', 'expires_at' => now()->addDay()]);

    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasErrors('import');

    ServerAccountImport::query()->update(['status' => 'ready', 'accounts' => null, 'expires_at' => now()->subMinute()]);
    $this->get(route('admin.services.servers.import.show', $this->server))->assertInertia(fn (Assert $page) => $page->where('import', null));
});

test('fetching needs a recent password, and the import pages need the sensitive import permission', function () {
    actingAsStaff();
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasErrors('password_confirmation');
    expect(ServerAccountImport::query()->count())->toBe(0);

    actingAsStaff(['services.*']);
    confirmPassword();
    $this->get(route('admin.services.servers.import.show', $this->server))->assertForbidden();
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertForbidden();
    expect(ServerAccountImport::query()->count())->toBe(0);
});
