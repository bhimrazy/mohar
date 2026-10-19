<?php

use App\Enums\ReconcileStatus;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CertificateInspector;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CpanelDriver;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Modules\Services\Services\ProvisioningSnapshots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\Whm\WhmSimulator;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The cPanel driver, unchanged, against the whm simulator over a real socket (ADR 0016 §3): the outbound guard
 * resolves and pins 127.0.0.1 (loopback allowed in tests only), the certificate is trusted through Test connection and
 * "Trust this certificate" as staff do it, and every later call is pinned to that key.
 */
beforeEach(function () {
    config(['integrations.outbound.allow_loopback' => true]);
    $this->scenario = Processes::start(WhmSimulator::class);
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Simulated WHM', 'hostname' => '127.0.0.1', 'port' => $this->scenario->apiPort(), 'username' => 'root', 'api_token' => WhmSimulator::ROOT_TOKEN]);
    $this->kit->priceBook->addProduct(41, 'Shared Basic', driver: 'cpanel', driverConfig: ['package' => 'root_basic']);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

/**
 * Test connection, then trust the fingerprint it showed, through the staff routes (recent password, sensitive
 * permission). Returns the first check.
 *
 * @return array<string, mixed>
 */
function trustSimulatedWhm(Server $server): array
{
    actingAsStaff();
    confirmPassword();
    test()->post(route('admin.services.servers.test', $server));
    $check = (array) app('session.store')->get('inertia.flash_data.connectionCheck');

    test()->post(route('admin.services.servers.trust-certificate', $server), ['fingerprint' => $check['details']['certificate_spki_sha256'] ?? ''])->assertSessionHasNoErrors();

    return $check;
}

function presentedPin(): string
{
    return (string) CertificateInspector::describe((string) file_get_contents(test()->scenario->ca()->leafPath()))['spki_sha256'];
}

function simulatedService(string $username = 'example'): Service
{
    return Service::factory()->pending()->create(['product_id' => 41, 'server_id' => test()->server->id, 'domain' => 'example-shop.test', 'username' => $username, 'password' => Whm::PASSWORD]);
}

test('staff trust the simulator\'s certificate the normal way, and later calls are pinned to it', function () {
    $first = trustSimulatedWhm($this->server);

    expect($first['ok'])->toBeFalse()
        ->and($first['steps'][0])->toMatchArray(['label' => 'Address and certificate', 'status' => 'fail'])
        ->and($first['details']['certificate_spki_sha256'])->toBe(presentedPin())
        ->and($first['can_trust'])->toBeTrue()
        ->and($this->server->refresh()->settings['spki_pin'])->toBe(presentedPin());

    $this->post(route('admin.services.servers.test', $this->server));
    $second = (array) app('session.store')->get('inertia.flash_data.connectionCheck');

    expect($second['ok'])->toBeTrue()
        ->and($second['message'])->toBe('Connected to WHM 11.124.0.12 (root).')
        ->and(array_column($second['steps'], 'status'))->each->toBe('pass')
        ->and($second['details'])->toMatchArray(['account_kind' => 'root', 'nameservers' => 'ns1.sim.test, ns2.sim.test', 'sso_available' => 'yes']);
});

test('the lifecycle runs through the host and leaves the server in the matching state, even with slow answers', function () {
    trustSimulatedWhm($this->server);
    $this->scenario->faults()->add('listpkgs', Fault::latency(800));
    $this->scenario->faults()->add('accountsummary', Fault::slowBody(300));
    $service = simulatedService();
    $commands = app(ModuleCommandDispatcher::class);
    $account = fn (): ?array => $this->scenario->state()->get('accounts', 'example');

    $commands->runNow($service, ModuleCommandName::Create);
    expect($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($account())->toMatchArray(['domain' => 'example-shop.test', 'plan' => 'root_basic', 'owner' => 'root', 'email' => $service->client->email])
        ->and($service->driver_data)->toMatchArray(['ip' => '203.0.113.20', 'owner' => 'root', 'plan' => 'root_basic']);

    $commands->runNow($service->refresh(), ModuleCommandName::Suspend, ['reason' => 'Overdue invoice']);
    expect($account()['suspended'])->toBe(1);

    $commands->runNow($service->refresh(), ModuleCommandName::Unsuspend);
    expect($account()['suspended'])->toBe(0);

    $commands->runNow($service->refresh(), ModuleCommandName::ChangePassword, ['password' => 'An0ther-Strong-Pass!']);
    expect(password_verify('An0ther-Strong-Pass!', (string) $this->scenario->state()->get('passwords', 'example')))->toBeTrue();

    $this->kit->priceBook->addProduct(42, 'Shared Pro', driver: 'cpanel', driverConfig: ['package' => 'root_pro']);
    $service->update(['product_id' => 42]);
    $commands->runNow($service->refresh(), ModuleCommandName::ChangePackage, ['previous' => ['product_id' => 41]]);
    expect($account()['plan'])->toBe('root_pro');

    $commands->runNow($service->refresh(), ModuleCommandName::Terminate);
    expect($account())->toBeNull()
        ->and($this->scenario->state()->get('zones', 'example-shop.test'))->toBeNull()
        ->and($service->refresh()->status)->toBe(ServiceStatus::Terminated)
        ->and($this->scenario->log()->count('createacct'))->toBe(1);
});

test('a create that commits and then hangs is unknown, and the verify sweep adopts the account it proves is ours', function () {
    // A deadline short enough for a test: createacct gets about 11 seconds, then the client gives up.
    config(['services.module.job_timeout_seconds' => 17]);
    trustSimulatedWhm($this->server);
    $this->scenario->faults()->add('createacct', Fault::commitThenHang(15));
    $service = simulatedService();

    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);
    $item = ModuleQueueItem::query()->where('subject_id', $service->id)->where('command', 'create')->sole();

    expect($service->refresh()->status)->toBe(ServiceStatus::Pending)
        ->and($item->kind)->toBe(ModuleQueueItem::KIND_VERIFY)
        ->and($this->scenario->state()->get('accounts', 'example'))->not->toBeNull();

    $this->artisan('services:verify-unknown')->assertSuccessful();

    expect($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($this->scenario->log()->count('createacct'))->toBe(1);
});

test('after a 5xx the sweep never adopts someone else\'s account with the same name, and a failed read sends no create', function () {
    trustSimulatedWhm($this->server);
    $service = simulatedService();

    // Every listpkgs fails, so the retries the sync test queue runs at once fail too.
    $this->scenario->faults()->add('listpkgs', Fault::status(503));
    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);
    expect($this->scenario->log()->count('listpkgs'))->toBeGreaterThan(0)
        ->and($this->scenario->log()->count('createacct'))->toBe(0)
        ->and($service->refresh()->status)->toBe(ServiceStatus::Pending);

    $this->scenario->faults()->clear();
    $this->scenario->faults()->add('createacct', Fault::status(503), nth: 1);
    ModuleQueueItem::query()->where('subject_id', $service->id)->delete();
    app(ModuleCommandDispatcher::class)->runNow($service->refresh(), ModuleCommandName::Create);
    $this->scenario->simulator()->actions()['add_account'](['user' => 'example', 'domain' => 'other-site.test', 'owner' => 'reseller1', 'plan' => 'reseller1_starter'], $this->scenario);

    $this->artisan('services:verify-unknown')->assertSuccessful();
    $item = ModuleQueueItem::query()->where('subject_id', $service->id)->where('command', 'create')->sole();

    expect($item->lookup_status)->toBe(ReconcileStatus::Conflict)
        ->and($service->refresh()->status)->toBe(ServiceStatus::Pending)
        ->and($this->scenario->state()->get('accounts', 'example')['owner'])->toBe('reseller1')
        ->and($this->scenario->log()->count('createacct'))->toBe(1);
});

test('a rotated certificate is refused before anything is sent, until staff trust the new one', function () {
    trustSimulatedWhm($this->server);
    $old = presentedPin();
    $this->scenario->ca()->rotateLeaf();
    $service = simulatedService();

    $outcome = app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    expect($outcome->message)->toContain('Server certificate changed')
        ->and($this->scenario->log()->entries())->toBe([])
        ->and($service->refresh()->status)->toBe(ServiceStatus::Pending);

    $again = trustSimulatedWhm($this->server->refresh());
    expect($again['details']['certificate_spki_sha256'])->toBe(presentedPin())->not->toBe($old)
        ->and($this->server->refresh()->settings['spki_pin'])->toBe(presentedPin());
});

test('a reseller login sees only its accounts and packages, and its login links work once', function () {
    $this->server->update(['username' => 'reseller1', 'api_token' => WhmSimulator::RESELLER_TOKEN]);
    $check = trustSimulatedWhm($this->server);
    $connection = app(ProvisioningSnapshots::class)->connection($this->server->refresh());
    $driver = app(CpanelDriver::class);

    expect($check['can_trust'])->toBeTrue()
        ->and($driver->testConnection($connection)->details['account_kind'])->toBe('reseller')
        ->and(array_map(fn ($account) => $account->username, $driver->listAccounts($connection)))->toBe(['bravo'])
        ->and(array_keys($driver->fieldOptions($connection, 'package')))->toBe(['reseller1_starter'])
        ->and($driver->create(Whm::context(['package' => 'root_pro'], $connection, 'shop', 'shop.test'))->message)->toContain('Package root_pro is not available to reseller1');

    $link = $driver->serviceSsoLink(Whm::context(server: $connection, username: 'bravo'));
    $browser = Http::withOptions(['verify' => $this->scenario->ca()->bundlePath()]);

    expect($browser->get($link->url)->body())->toContain('Signed in to cPanel as bravo')
        ->and($browser->get($link->url)->status())->toBe(403);
});
