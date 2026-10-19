<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * The Import accounts page (cPanel spec §10): it shows the match stored when the fetch finished, filters and pages it,
 * and re-checks only the rows it shows. "Match again" re-matches without asking the server.
 */
beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_basic']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com']);
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
    actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertRedirect(route('admin.services.servers.import.show', $this->server));
});

/**
 * @return array<string, array<string, mixed>>
 */
function pageRows(Server $server, array $query = []): array
{
    return collect(test()->get(route('admin.services.servers.import.show', [$server, ...$query]))->assertOk()->inertiaProps('rows.data'))->keyBy('username')->all();
}

test('the page shows the stored match with its counts, filtered by tab and search', function () {
    $this->get(route('admin.services.servers.import.show', $this->server))->assertInertia(fn (Assert $page) => $page
        ->component('admin/services/servers/import')
        ->where('counts', ['all' => 3, 'proposed' => 1, 'unmatched' => 1, 'reseller' => 1, 'linked' => 0, 'missing' => 0])
        ->where('import.status', 'ready')
        ->has('rows.data', 3));

    expect(pageRows($this->server)['alpha'])
        ->category->toBe('proposed')
        ->client->toBe(['id' => $this->anna->id, 'name' => $this->anna->display_name])
        ->product->toBe(['id' => 21, 'name' => 'Basic hosting'])
        ->and(array_keys(pageRows($this->server, ['tab' => 'reseller'])))->toBe(['bravo'])
        ->and(array_keys(pageRows($this->server, ['search' => 'CAFE'])))->toBe(['bravo']);
});

test('the rows shown are re-checked: a service created since links the account, a client closed since is dropped', function () {
    $service = Service::factory()->create(['server_id' => $this->server->id, 'username' => 'charlie', 'status' => ServiceStatus::Active]);
    $this->anna->update(['status' => 'closed']);

    $rows = pageRows($this->server);

    expect($rows['charlie'])->category->toBe('linked')->service->id->toBe($service->id)
        ->and($rows['charlie']['note'])->toContain('A service took this account after the list was fetched.')
        ->and($rows['alpha']['client'])->toBeNull()
        ->and($rows['alpha']['note'])->toContain('The matched client was closed or removed since.');
});

test('Match again re-matches the stored list against today, with the user the fetch signed in as', function () {
    Client::factory()->create(['email' => 'anna@alpha-books.com']);
    $this->server->update(['username' => 'reseller1']);
    Whm::fake([]);

    $this->post(route('admin.services.servers.import.rematch', $this->server))->assertSessionHasNoErrors();

    expect(pageRows($this->server)['alpha'])->category->toBe('unmatched')->note->toBe('2 clients share this email.')
        ->and(pageRows($this->server)['bravo']['category'])->toBe('reseller');
});

test('missing services link to the service, and one ended since drops out', function () {
    $missing = Service::factory()->create(['server_id' => $this->server->id, 'username' => 'delta', 'status' => ServiceStatus::Active, 'created_at' => now()->subDay()]);
    $ended = Service::factory()->create(['server_id' => $this->server->id, 'username' => 'echo', 'status' => ServiceStatus::Active, 'created_at' => now()->subDay()]);
    Service::factory()->create(['server_id' => $this->server->id, 'username' => 'foxtrot', 'status' => ServiceStatus::Active, 'created_at' => now()->addMinute()]);
    $this->post(route('admin.services.servers.import.rematch', $this->server));
    $ended->update(['status' => ServiceStatus::Terminated]);

    $rows = pageRows($this->server, ['tab' => 'missing']);

    expect(array_keys($rows))->toBe(['delta'])
        ->and($rows['delta']['service']['id'])->toBe($missing->id)
        ->and($missing->refresh()->status)->toBe(ServiceStatus::Active);
});
