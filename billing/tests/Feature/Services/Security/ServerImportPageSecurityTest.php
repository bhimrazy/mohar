<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Security review of #101 (Import accounts page, step 3a-2): Match again is a sensitive mutation (permission,
 * step-up, throttle, audit) bound to the server in the URL; the page and its polling show only that server's list.
 */
beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->group = ServerGroup::factory()->create();
    $this->kit->priceBook->addProduct(21, 'Basic hosting', driver: 'cpanel', serverGroupId: $this->group->id, driverConfig: ['package' => 'root_basic']);
    Client::factory()->create(['email' => 'alpha@example.com']);
});

function pgServer(string $name): Server
{
    $server = Server::factory()->create(['driver' => 'cpanel', 'name' => $name, 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    test()->group->servers()->attach($server, ['sort_order' => 0]);

    return $server;
}

function pgFetched(Server $server, string $user): ServerAccountImport
{
    Whm::fake(['listaccts' => ['metadata' => ['result' => 1, 'reason' => 'OK', 'command' => 'listaccts', 'version' => 1], 'data' => ['acct' => [[
        'user' => $user, 'domain' => "{$user}.example.com", 'plan' => 'root_basic', 'owner' => 'root', 'email' => "{$user}@example.com",
        'ip' => '203.0.113.20', 'suspended' => 0, 'suspendreason' => 'not suspended', 'unix_startdate' => 1700000000, 'diskused' => '1M', 'disklimit' => '100M',
    ]]]]]);
    test()->post(route('admin.services.servers.import.fetch', $server))->assertSessionHasNoErrors();

    return ServerAccountImport::query()->where('server_id', $server->id)->sole();
}

test('Match again needs the exact import permission: module and group wildcards get 403', function () {
    actingAsStaff();
    confirmPassword();
    $server = pgServer('Shared 1');
    $before = pgFetched($server, 'alpha')->updated_at;

    foreach ([['services.*'], ['services.servers.*'], ['services.servers.view', 'services.servers.manage']] as $permissions) {
        actingAsStaff($permissions);
        confirmPassword();
        $this->post(route('admin.services.servers.import.rematch', $server))->assertForbidden();
    }

    expect(ServerAccountImport::query()->sole()->updated_at->equalTo($before))->toBeTrue();
});

test('Match again needs a recent password, like every mutation behind a sensitive permission', function () {
    actingAsStaff();
    confirmPassword();
    $server = pgServer('Shared 1');
    pgFetched($server, 'alpha');

    $this->flushSession();
    actingAsStaff(['services.servers.import']);
    // Control: the session holds no recent confirmation, so fetch asks for the password.
    $this->post(route('admin.services.servers.import.fetch', $server))->assertSessionHasErrors('password_confirmation');

    $this->post(route('admin.services.servers.import.rematch', $server))->assertSessionHasErrors('password_confirmation');
});

test('Match again is audited against the staff member', function () {
    actingAsStaff();
    confirmPassword();
    $server = pgServer('Shared 1');
    pgFetched($server, 'alpha');
    $staff = actingAsStaff(['services.servers.import']);
    confirmPassword();

    $this->post(route('admin.services.servers.import.rematch', $server))->assertSessionHasNoErrors();

    expect(ActivityLog::query()->where('subject_id', $server->id)->where('user_id', $staff->id)->count())->toBe(1);
});

test('Match again is throttled at six a minute', function () {
    actingAsStaff();
    confirmPassword();
    $server = pgServer('Shared 1');
    pgFetched($server, 'alpha');
    actingAsStaff(); // a fresh user: the throttle bucket is per user, shared by every throttle:6,1 route
    confirmPassword();

    foreach (range(1, 6) as $attempt) {
        $this->post(route('admin.services.servers.import.rematch', $server))->assertRedirect();
    }

    $this->post(route('admin.services.servers.import.rematch', $server))->assertTooManyRequests();
});

test('Match again on one server never touches another server\'s list, and a server without a list is refused', function () {
    actingAsStaff();
    confirmPassword();
    $a = pgServer('Shared 1');
    $b = pgServer('Shared 2');
    $listA = pgFetched($a, 'alpha');
    $this->travel(1)->minutes();

    $this->post(route('admin.services.servers.import.rematch', $b))->assertSessionHasErrors('import');
    expect($listA->refresh()->updated_at->lessThan(now()->subSeconds(30)))->toBeTrue();

    $listB = pgFetched($b, 'bravo');
    $this->travel(1)->minutes();
    $this->post(route('admin.services.servers.import.rematch', $b))->assertSessionHasNoErrors();

    expect($listA->refresh()->updated_at->lessThan($listB->refresh()->updated_at))->toBeTrue()
        ->and(array_column($listA->accountRows(), 'username'))->toBe(['alpha'])
        ->and(array_column($listB->accountRows(), 'username'))->toBe(['bravo']);
});

test('the polling reload of one server\'s page carries only that server\'s list and counts', function () {
    actingAsStaff();
    confirmPassword();
    $a = pgServer('Shared 1');
    $b = pgServer('Shared 2');
    pgFetched($a, 'alpha');
    pgFetched($b, 'bravo');
    $version = $this->get(route('admin.services.servers.import.show', $b))->inertiaPage()['version'];

    $response = $this->get(route('admin.services.servers.import.show', $b), [
        'X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'admin/services/servers/import', 'X-Inertia-Partial-Data' => 'import,counts,rows',
    ])->assertOk();

    expect(array_column($response->json('props.rows.data'), 'username'))->toBe(['bravo'])
        ->and($response->json('props.counts.all'))->toBe(1)
        ->and($response->getContent())->not->toContain('alpha');
});

test('the missing tab lists only this server\'s services, even when the stored ids name another server\'s', function () {
    actingAsStaff();
    confirmPassword();
    $a = pgServer('Shared 1');
    $b = pgServer('Shared 2');
    $foreign = Service::factory()->create(['server_id' => $a->id, 'username' => 'zulu', 'status' => ServiceStatus::Active]);
    $own = Service::factory()->create(['server_id' => $b->id, 'username' => 'yankee', 'status' => ServiceStatus::Active]);
    pgFetched($b, 'bravo')->update(['missing' => [$foreign->id, $own->id]]);

    $rows = $this->get(route('admin.services.servers.import.show', [$b, 'tab' => 'missing']))->assertOk()->inertiaProps('rows.data');

    expect(array_column(array_column($rows, 'service'), 'id'))->toBe([$own->id]);
});

test('the create-product and service links are fixed routes, with nothing from the account in them', function () {
    actingAsStaff();
    confirmPassword();
    $server = pgServer('Shared 1');
    Whm::fake(['listaccts' => ['metadata' => ['result' => 1, 'reason' => 'OK', 'command' => 'listaccts', 'version' => 1], 'data' => ['acct' => [[
        'user' => 'alpha', 'domain' => 'alpha.example.com', 'plan' => 'evil plan', 'owner' => 'root', 'email' => 'alpha@example.com',
        'ip' => '203.0.113.20', 'suspended' => 0, 'suspendreason' => 'not suspended', 'unix_startdate' => 1700000000, 'diskused' => '1M', 'disklimit' => '100M',
    ]]]]]);
    $this->post(route('admin.services.servers.import.fetch', $server))->assertSessionHasNoErrors();

    $this->get(route('admin.services.servers.import.show', $server))->assertInertia(fn (Assert $page) => $page
        ->where('createProductUrl', route('admin.catalog.products.create'))
        ->where('rows.data.0.category', 'unmatched'));

    expect(parse_url(route('admin.catalog.products.create'), PHP_URL_QUERY))->toBeNull();
});
