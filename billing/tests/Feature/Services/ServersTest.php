<?php

use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\FillType;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
});

function serverPayload(array $overrides = []): array
{
    return [
        'name' => 'Web 1',
        'hostname' => 'WEB1.example.net',
        'ip_address' => '203.0.113.10',
        'driver' => 'log',
        'username' => 'root',
        'password' => 'p@ss-word',
        'secure' => true,
        'max_accounts' => 200,
        'is_active' => true,
        'is_default' => true,
        'nameservers' => ['ns1.example.net', 'ns2.example.net'],
        'monthly_cost' => 4900,
        'currency' => 'USD',
        ...$overrides,
    ];
}

test('staff manage servers and credentials are stored encrypted and never exposed', function () {
    actingAsStaff(['services.servers.view', 'services.servers.manage']);
    $this->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()]);

    $this->post(route('admin.services.servers.store'), serverPayload())->assertRedirect(route('admin.services.servers.index'));

    $server = Server::query()->sole();
    expect($server)->hostname->toBe('web1.example.net')->password->toBe('p@ss-word')
        ->and($server->getRawOriginal('password'))->not->toBe('p@ss-word');

    $this->get(route('admin.services.servers.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/services/servers/index')
            ->where('servers.0.has_password', true)
            ->missing('servers.0.password')
            ->where('servers.0.active_accounts', 0));

    $this->put(route('admin.services.servers.update', $server), serverPayload(['name' => 'Web One', 'password' => '']))->assertRedirect();
    expect($server->refresh())->name->toBe('Web One')->password->toBe('p@ss-word');
});

test('only one default server per driver is kept', function () {
    actingAsStaff(['services.servers.view', 'services.servers.manage']);
    $this->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()]);
    $old = Server::factory()->create(['is_default' => true]);

    $this->post(route('admin.services.servers.store'), serverPayload())->assertRedirect();

    expect($old->refresh()->is_default)->toBeFalse();
});

test('server input is validated', function () {
    actingAsStaff(['services.servers.view', 'services.servers.manage']);
    $this->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()]);

    $this->post(route('admin.services.servers.store'), serverPayload(['hostname' => 'bad host!', 'driver' => 'cpanel-x', 'nameservers' => ['a', 'b', 'c', 'd', 'e', 'f'], 'port' => 70000]))
        ->assertSessionHasErrors(['hostname', 'driver', 'nameservers', 'port']);
});

test('a server with services cannot be deleted', function () {
    actingAsStaff(['services.servers.view', 'services.servers.manage']);
    $this->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()]);
    $server = Server::factory()->create();
    Service::factory()->status(ServiceStatus::Terminated)->create(['server_id' => $server->id]);

    $this->delete(route('admin.services.servers.destroy', $server))->assertSessionHasErrors(['server']);
    expect(Server::query()->whereKey($server->id)->exists())->toBeTrue();

    $empty = Server::factory()->create();
    $this->delete(route('admin.services.servers.destroy', $empty))->assertRedirect();
    expect(Server::query()->whereKey($empty->id)->exists())->toBeFalse();
});

test('test connection reports success and failure', function () {
    actingAsStaff(['services.servers.view', 'services.servers.manage']);
    $this->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()]);
    $server = Server::factory()->create();

    $this->post(route('admin.services.servers.test', $server))->assertSessionHasNoErrors();

    $this->kit->driver->failNext('test_connection', message: 'Login refused');
    $this->post(route('admin.services.servers.test', $server))->assertSessionHasErrors(['server' => 'Connection to '.$server->hostname.' failed: Login refused']);
});

test('server groups keep their fill type and server order', function () {
    actingAsStaff(['services.servers.view', 'services.servers.manage']);
    $this->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()]);
    [$a, $b] = Server::factory()->count(2)->create();

    $this->post(route('admin.services.server-groups.store'), ['name' => 'Shared EU', 'fill_type' => 'fill_sequentially', 'server_ids' => [$b->id, $a->id]])->assertRedirect();

    $group = ServerGroup::query()->sole();
    expect($group->fill_type)->toBe(FillType::FillSequentially)
        ->and($group->servers()->pluck('servers.id')->all())->toBe([$b->id, $a->id]);

    $this->put(route('admin.services.server-groups.update', $group), ['name' => 'Shared EU', 'fill_type' => 'least_full', 'server_ids' => [$a->id]])->assertRedirect();
    expect($group->refresh()->servers()->pluck('servers.id')->all())->toBe([$a->id]);
});

test('viewing servers does not allow managing them', function () {
    actingAsStaff(['services.servers.view']);

    $this->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()]);
    $this->get(route('admin.services.servers.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    $this->post(route('admin.services.servers.store'), serverPayload())->assertForbidden();
    $this->post(route('admin.services.servers.test', Server::factory()->create()))->assertForbidden();
});
