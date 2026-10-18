<?php

use App\Data\Services\Provisioning\PreviousPackage;
use App\Data\Services\Provisioning\UsernamePolicy;
use App\Enums\DriverOutcome;
use App\Enums\ReconcileStatus;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CpanelDriver;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\QueueStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Modules\Services\Support\UsernameGenerator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

beforeEach(function () {
    $this->driver = app(CpanelDriver::class);
});

/**
 * A pending cPanel service on a cPanel server, for tests that run through the host.
 */
function pendingCpanelService(string $package = 'root_basic'): Service
{
    Currency::factory()->create();
    $kit = ServicesTestKit::bind();
    $server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $kit->priceBook->addProduct(41, 'Shared Basic', driver: 'cpanel', driverConfig: ['package' => $package]);

    return Service::factory()->pending()->create(['product_id' => 41, 'server_id' => $server->id, 'domain' => 'example-shop.com', 'username' => 'example', 'password' => Whm::PASSWORD]);
}

test('create sends the account with its package and contact email, and the read-back fills the service', function () {
    Whm::fake();
    $service = pendingCpanelService();

    $outcome = app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    expect($outcome->succeededNow())->toBeTrue()
        ->and(Whm::sent('createacct')[0]->data())->toMatchArray([
            'username' => 'example',
            'domain' => 'example-shop.com',
            'plan' => 'root_basic',
            'password' => Whm::PASSWORD,
            'contactemail' => $service->client->email,
            'showpass' => 'n',
            'forcedns' => '0',
        ])
        ->and($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($service->driver_data)->toMatchArray(['ip' => '203.0.113.20', 'owner' => 'root', 'plan' => 'root_basic']);
});

test('a username taken by an account that is not ours is not created: the driver proposes the next free name', function () {
    Whm::fake([
        'verify_new_username' => fn (Request $request) => Http::response(Whm::fixture(Whm::params($request)['user'] === 'examples' ? 'verify_new_username_taken' : 'verify_new_username_ok')),
        'listaccts' => 'listaccts_foreign',
    ]);

    $result = $this->driver->create(Whm::context(username: 'examples'));

    expect($result->outcome())->toBe(DriverOutcome::Retryable)
        ->and($result->data)->toBe(['username' => 'example1'])
        ->and(Whm::sent('createacct'))->toBeEmpty();
});

test('the host stores the proposed name before sending it, so the account is created as example1', function () {
    Whm::fake([
        'verify_new_username' => fn (Request $request) => Http::response(Whm::fixture(Whm::params($request)['user'] === 'examples' ? 'verify_new_username_taken' : 'verify_new_username_ok')),
        'listaccts' => 'listaccts_foreign',
    ]);
    $service = pendingCpanelService();
    $service->update(['username' => 'examples']);

    $outcome = app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    expect($outcome->succeededNow())->toBeTrue()
        ->and($service->refresh()->username)->toBe('example1')
        ->and(Whm::sent('createacct'))->toHaveCount(1)
        ->and(Whm::sent('createacct')[0]->data()['username'])->toBe('example1')
        ->and(ModuleCommand::query()->where('service_id', $service->id)->orderBy('id')->pluck('request')->map(fn ($request) => $request['target']['username'] ?? null)->all())->toBe(['examples', 'example1']);
});

test('generated usernames never start with test or end with assword, and fit 8 characters', function (string $domain) {
    $policy = app(CpanelDriver::class)->metadata()->usernamePolicy;
    $username = app(UsernameGenerator::class)->generate($domain, null, null, $policy);

    expect($username)->not->toStartWith('test')
        ->and($username)->not->toEndWith('assword')
        ->and(strlen($username))->toBeLessThanOrEqual(8)
        ->and($policy?->violation($username))->toBeNull();
})->with(['testsite.com', 'password.com']);

test('an existing account is adopted only when our earlier unknown attempt created it', function () {
    $attempt = now()->subMinutes(20)->toImmutable();
    $ours = Whm::accounts(['unix_startdate' => $attempt->addMinutes(2)->getTimestamp()]);
    Whm::fake(['verify_new_username' => 'verify_new_username_taken', 'listaccts' => $ours, 'accountsummary' => 'accountsummary']);

    $result = $this->driver->create(Whm::context(unknownAttemptAt: $attempt));

    expect($result->success)->toBeTrue()
        ->and($result->message)->toContain('earlier attempt')
        ->and($result->data)->not->toHaveKey('username')
        ->and(Whm::sent('createacct'))->toBeEmpty();
});

test('an account our attempt did not create is never adopted, whatever its owner', function (array $account, ?string $domain, bool $withAttempt) {
    $attempt = now()->subMinutes(20)->toImmutable();
    Whm::fake([
        'verify_new_username' => fn (Request $request) => Http::response(Whm::fixture(Whm::params($request)['user'] === 'example' ? 'verify_new_username_taken' : 'verify_new_username_ok')),
        'listaccts' => Whm::accounts($account),
    ]);

    $result = $this->driver->create(Whm::context(['package' => 'root_basic', 'allow_temporary_domain' => true], domain: $domain, unknownAttemptAt: $withAttempt ? $attempt : null));

    expect($result->success)->toBeFalse()
        ->and($result->data)->toBe(['username' => 'example1'])
        ->and(Whm::sent('createacct'))->toBeEmpty();
})->with([
    'another customer, same domain, no attempt of ours' => [['unix_startdate' => 1893456000], 'example-shop.com', false],
    'created before our attempt' => [['unix_startdate' => 1262304000], 'example-shop.com', true],
    'no domain on the service' => [['unix_startdate' => 1893456000, 'domain' => 'unrelated-customer.com'], null, true],
    'another domain' => [['unix_startdate' => 1893456000, 'domain' => 'other-site.net'], 'example-shop.com', true],
]);

test('a retried create after an unknown outcome adopts the account that attempt made', function () {
    Whm::fake(['createacct' => Http::failedConnection('cURL error 28: Operation timed out after 50001 milliseconds with 0 bytes received')]);
    $service = pendingCpanelService();
    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);
    $item = ModuleQueueItem::query()->where('subject_id', $service->id)->sole();

    // The server check found nothing, so staff may create again; meanwhile the slow attempt's account appeared.
    $item->update(['lookup_status' => ReconcileStatus::NotExecuted]);
    Whm::fake(['verify_new_username' => 'verify_new_username_taken', 'listaccts' => Whm::accounts(['unix_startdate' => now()->getTimestamp()])]);
    actingAsStaff(['services.module_log.view', 'services.module_log.manage']);
    $this->post(route('admin.services.module-log.retry', $item))->assertSessionHasNoErrors();

    expect($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and(Whm::sent('createacct'))->toBeEmpty()
        ->and($item->refresh()->status)->toBe(QueueStatus::Resolved);
});

test('a username outside cPanel\'s rules, or a missing domain, is rejected without a remote call', function (string $username, ?string $domain, string $message) {
    Whm::fake();

    $result = $this->driver->create(Whm::context(username: $username, domain: $domain));

    expect($result->outcome())->toBe(DriverOutcome::Rejected)
        ->and($result->message)->toContain($message);
    Http::assertNothingSent();
})->with([
    'reserved prefix' => ['testshop', 'example-shop.com', UsernamePolicy::RESERVED_PREFIX],
    'reserved name' => ['root', 'example-shop.com', UsernamePolicy::RESERVED_NAME],
    'too long' => ['examplesh0p', 'example-shop.com', UsernamePolicy::TOO_LONG],
    'no domain' => ['example', null, 'A domain is required'],
]);

test('a product may allow accounts without a domain, and WHM then assigns one', function () {
    Whm::fake();

    $result = $this->driver->create(Whm::context(['package' => 'root_basic', 'allow_temporary_domain' => true], domain: null));

    expect($result->success)->toBeTrue()
        ->and(Whm::sent('createacct')[0]->data())->not->toHaveKey('domain');
});

test('a dedicated IP is requested when the product or a config option asks for it, and the contact email can be left out', function () {
    Whm::fake();

    $result = $this->driver->create(Whm::context(['package' => 'root_basic', 'send_contact_email' => false], configOptions: ['dedicated_ip' => '1']));

    expect(Whm::sent('createacct')[0]->data())->toMatchArray(['ip' => 'y'])
        ->and(Whm::sent('createacct')[0]->data())->not->toHaveKey('contactemail')
        ->and($result->data['dedicated_ip'])->toBe('203.0.113.20');
});

test('WHM refusals of createacct are rejected with our own message, and the raw reason only reaches the log', function (string $fixture, string $message) {
    Whm::fake(['createacct' => $fixture]);

    $result = $this->driver->create(Whm::context());
    $raw = Whm::fixture($fixture)['metadata']['reason'];

    expect($result->outcome())->toBe(DriverOutcome::Rejected)
        ->and($result->message)->toContain($message)
        ->and($result->message)->not->toContain($raw)
        ->and(collect($result->response['calls'])->firstWhere('function', 'createacct')['reason'])->toBe($raw);
})->with([
    'reserved' => ['createacct_reserved', 'cPanel reserves the username "example"'],
    'domain exists' => ['createacct_domain_exists', 'Domain already on server: example-shop.com'],
    'package not allowed' => ['createacct_package_not_allowed', 'Package root_basic is not available to root on Shared 1'],
    'weak password' => ['createacct_weak_password', 'too weak'],
    'theme' => ['createacct_invalid_theme', 'theme or language'],
]);

test('a username taken between the check and the create is retried, not refused', function () {
    Whm::fake(['createacct' => 'createacct_username_taken']);

    expect($this->driver->create(Whm::context())->outcome())->toBe(DriverOutcome::Retryable);
});

test('a package the connection cannot assign is rejected before any mutating call, naming package and server', function () {
    Whm::fake();

    $create = $this->driver->create(Whm::context(['package' => 'root_gold']));
    $change = $this->driver->changePackage(Whm::context(['package' => 'root_gold']), new PreviousPackage);

    foreach ([$create, $change] as $result) {
        expect($result->outcome())->toBe(DriverOutcome::Rejected)
            ->and($result->message)->toBe('Package root_gold is not available to root on Shared 1.');
    }

    expect(Whm::sent('createacct'))->toBeEmpty()
        ->and(Whm::sent('changepackage'))->toBeEmpty();
});

test('a create that times out after sending is unknown, opens a verify item and is not retried', function () {
    Whm::fake(['createacct' => Http::failedConnection('cURL error 28: Operation timed out after 50001 milliseconds with 0 bytes received')]);
    $service = pendingCpanelService();

    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);
    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    $item = ModuleQueueItem::query()->where('subject_id', $service->id)->sole();
    expect($item->kind)->toBe(ModuleQueueItem::KIND_VERIFY)
        ->and($item->status)->toBe(QueueStatus::Open)
        ->and(ModuleCommand::query()->where('service_id', $service->id)->value('status'))->toBe(ModuleCommand::UNKNOWN)
        ->and(Whm::sent('createacct'))->toHaveCount(1)
        ->and($service->refresh()->status)->toBe(ServiceStatus::Pending);
});

test('a connection refused on create is retried, and HTTP 401 is rejected naming the credentials', function () {
    Whm::fake(['listpkgs' => Http::failedConnection('cURL error 7: Failed to connect to whm.example.test port 2087: Connection refused')]);
    expect($this->driver->create(Whm::context())->outcome())->toBe(DriverOutcome::Retryable);

    Whm::fake(['listpkgs' => Http::response(Whm::raw('http_401.html'), 401)]);
    $rejected = $this->driver->create(Whm::context());
    expect($rejected->outcome())->toBe(DriverOutcome::Rejected)
        ->and($rejected->message)->toContain('credentials')->toContain('ACLs');
});

test('suspend sends the reason and keeps the account unsuspendable by resellers', function () {
    Whm::fake();

    $this->driver->suspend(Whm::context(), "Overdue invoice\n\x07".str_repeat('x', 200));

    $params = Whm::sent('suspendacct')[0]->data();
    expect($params['reason'])->toStartWith('Overdue invoice x')
        ->and(mb_strlen($params['reason']))->toBe(100)
        ->and($params['disallowun'])->toEqual(0);
});

test('a refused suspend of an account that is already suspended completes; unsuspend mirrors it', function () {
    Whm::fake(['suspendacct' => 'suspendacct_already', 'accountsummary' => 'accountsummary_suspended']);
    expect($this->driver->suspend(Whm::context(), 'Overdue invoice')->success)->toBeTrue();

    Whm::fake(['unsuspendacct' => 'unsuspendacct_locked', 'accountsummary' => 'accountsummary']);
    expect($this->driver->unsuspend(Whm::context())->success)->toBeTrue();
});

test('unsuspending an account the administrator locked is rejected', function () {
    Whm::fake(['unsuspendacct' => 'unsuspendacct_locked', 'accountsummary' => 'accountsummary_locked']);

    $result = $this->driver->unsuspend(Whm::context(server: Whm::server(username: 'reseller1')));

    expect($result->outcome())->toBe(DriverOutcome::Rejected)
        ->and($result->message)->toContain('suspended by the server administrator');
});

test('terminate removes the DNS zone unless the server keeps it, and an absent account completes', function () {
    Whm::fake();
    $this->driver->terminate(Whm::context());
    $this->driver->terminate(Whm::context(server: Whm::server(['keepdns_on_auto_terminate' => true])));
    expect(array_map(fn ($request) => $request->data()['keepdns'], Whm::sent('removeacct')))->toEqual([0, 1]);

    Whm::fake(['removeacct' => 'removeacct_missing', 'listaccts' => 'listaccts_empty']);
    $absent = $this->driver->terminate(Whm::context());
    expect($absent->success)->toBeTrue()
        ->and($absent->message)->toBe('The account was not on the server.');
});

test('a credential or permission refusal is never taken for an absent account', function () {
    Whm::fake(['removeacct' => Whm::fixture('access_denied'), 'listaccts' => 'listaccts_empty']);

    $result = $this->driver->terminate(Whm::context());

    expect($result->outcome())->toBe(DriverOutcome::Rejected)
        ->and(Whm::sent('listaccts'))->toBeEmpty();
});

test('the driver works to the deadline the host sets, and sends nothing once it has passed', function () {
    Whm::fake();
    expect($this->driver->create(Whm::context(deadline: now()->toImmutable()->subSecond()))->outcome())->toBe(DriverOutcome::Retryable);
    Http::assertNothingSent();

    // The host's deadline is the command timeout from its own clock; a clock an hour behind puts it in the past.
    $service = pendingCpanelService();
    $this->travelTo(now()->subHour());
    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    expect(Whm::sent('createacct'))->toBeEmpty()
        ->and(ModuleCommand::query()->where('service_id', $service->id)->value('error'))->toContain('ran out of time');
});
