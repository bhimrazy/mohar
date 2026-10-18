<?php

use App\Contracts\Services\Provisioning\ProvidesFieldOptions;
use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Contracts\Services\ProvisioningDrivers;
use App\Data\Integrations\SettingField;
use App\Data\Services\Provisioning\DriverMetadata;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Data\Services\Provisioning\ServerConnection;
use App\Data\Services\Provisioning\UsernamePolicy;
use App\Enums\FieldType;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Jobs\RunModuleCommand;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Modules\Services\Support\ServiceSettings;
use App\Modules\Services\Support\UsernameGenerator;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Fixtures\Extensions\AcmePanel\AcmePanelServiceProvider;

beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->app->register(AcmePanelServiceProvider::class);
});

/**
 * A driver with a username policy, a long command timeout and remote-filled package options.
 */
function strictPanel(array $packagesByServer = []): ProvisioningDriver
{
    return new class($packagesByServer) implements ProvidesFieldOptions, ProvisioningDriver
    {
        public int $optionCalls = 0;

        public function __construct(private array $packagesByServer) {}

        public function key(): string
        {
            return 'strict_panel';
        }

        public function name(): string
        {
            return 'Strict panel';
        }

        public function metadata(): DriverMetadata
        {
            return new DriverMetadata(
                requiresServer: true,
                productConfigFields: [new SettingField('package', 'Package', FieldType::Select, optionsSource: SettingField::OPTIONS_REMOTE)],
                usernamePolicy: new UsernamePolicy(maxLength: 6, reservedPrefixes: ['test'], reservedNames: ['admin']),
                commandTimeoutSeconds: 150,
            );
        }

        public function fieldOptions(ServerConnection $server, string $fieldKey): array
        {
            $this->optionCalls++;

            return $this->packagesByServer[$server->hostname] ?? throw new RuntimeException("Couldn't reach {$server->hostname} with {$server->password}");
        }

        public function create(ProvisioningContext $context): DriverResult
        {
            return DriverResult::ok();
        }

        public function suspend(ProvisioningContext $context, string $reason): DriverResult
        {
            return DriverResult::ok();
        }

        public function unsuspend(ProvisioningContext $context): DriverResult
        {
            return DriverResult::ok();
        }

        public function terminate(ProvisioningContext $context): DriverResult
        {
            return DriverResult::ok();
        }
    };
}

test('a package driver provisions through the host with a snapshot, and its echoed secret settings are redacted', function () {
    Http::fake(['web1.acme.test:8443/api/accounts' => Http::response(['id' => 'acme-77', 'status' => 'created'])]);
    $server = Server::factory()->create(['driver' => 'acme_panel', 'hostname' => 'web1.acme.test', 'port' => 8443, 'api_token' => 'acme-token-1']);
    $this->kit->priceBook->addProduct(31, 'Acme starter', driver: 'acme_panel', driverConfig: ['plan' => 'starter', 'reseller_key' => 'RS-Secret-991']);
    $service = Service::factory()->pending()->create(['product_id' => 31, 'server_id' => $server->id, 'domain' => 'acme-shop.example']);

    $outcome = app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    expect($outcome->succeededNow())->toBeTrue()
        ->and($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($service->driver_data)->toBe(['acme_id' => 'acme-77']);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer acme-token-1')
        && $request['plan'] === 'starter' && $request['email'] === $service->client->email && $request['reseller'] === 'RS-Secret-991');

    $log = json_encode(ModuleCommand::query()->where('service_id', $service->id)->sole()->only(['request', 'response', 'error']));
    expect($log)->not->toContain('RS-Secret-991')
        ->not->toContain((string) $service->password)
        ->toContain(Redactor::MASK);
});

test('an unknown outcome is not retried: the attempt is logged as unknown and a verify item blocks another create', function () {
    $service = Service::factory()->pending()->create(['server_id' => Server::factory()->create()->id]);
    $this->kit->driver->failNext('create', message: 'Read timed out after the request was sent.', unknown: true);

    $outcome = app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    expect($outcome->status)->toBe('failed')
        ->and(ModuleCommand::query()->where('service_id', $service->id)->sole()->status)->toBe(ModuleCommand::UNKNOWN)
        ->and(ModuleQueueItem::query()->where('subject_id', $service->id)->sole())->kind->toBe(ModuleQueueItem::KIND_VERIFY)
        ->and(app(ExecuteModuleCommand::class)->awaitingVerification($service->id))->toBeTrue()
        ->and($service->refresh()->status)->toBe(ServiceStatus::Pending);

    RunModuleCommand::dispatch($service->id, 'create');

    expect($this->kit->driver->callsFor('create'))->toHaveCount(1);
});

test('a capability the driver lacks is neither offered nor run', function () {
    actingAsStaff(['services.services.view']);
    $server = Server::factory()->create(['driver' => 'acme_panel']);
    $this->kit->priceBook->addProduct(32, 'Acme', driver: 'acme_panel');
    $service = Service::factory()->onServer($server)->create(['product_id' => 32]);

    $this->get(route('admin.services.services.show', $service))->assertInertia(fn (Assert $page) => $page
        ->where('driver.key', 'acme_panel')
        ->where('driver.capabilities', [ProvisioningDriverRegistry::CHANGE_PASSWORD, ProvisioningDriverRegistry::TEST_CONNECTION])
        ->where('commands', fn ($commands) => collect($commands)->pluck('value')->all() === ['suspend', 'terminate', 'change_password']));

    $outcome = app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::ChangePackage);

    expect($outcome->message)->toBe('The Acme Panel driver cannot change package; make the change on the server by hand.');
    Http::assertNothingSent();
});

test('testing a server whose driver has no connection test says so instead of failing', function () {
    actingAsStaff(['services.servers.view', 'services.servers.manage']);
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->post(route('admin.services.servers.test', Server::factory()->create(['driver' => 'none'])))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'None (manual) servers have nothing to connect to.');
});

test('generated and staff-typed usernames follow the driver username policy', function () {
    app(ProvisioningDriverRegistry::class)->register('strict_panel', fn () => strictPanel());
    $policy = app(ProvisioningDriverRegistry::class)->metadata('strict_panel')->usernamePolicy;
    $server = Server::factory()->create(['driver' => 'strict_panel']);
    Service::factory()->create(['server_id' => $server->id, 'username' => 'shopex']);

    expect(app(UsernameGenerator::class)->generate('shopexample.com', $server->id, policy: $policy))->toBe('shope1')
        ->and(app(UsernameGenerator::class)->generate('testing.example', $server->id, policy: $policy))->not->toStartWith('test')
        ->and(app(UsernameGenerator::class)->generate('shopexample.com', $server->id))->toBe('shopexam');

    actingAsStaff(['services.services.view', 'services.services.manage']);
    $this->kit->priceBook->addProduct(33, 'Strict', driver: 'strict_panel');
    $service = Service::factory()->onServer($server)->create(['product_id' => 33]);
    $payload = fn (string $username): array => ['username' => $username, 'cycle' => $service->cycle->value, 'quantity' => 1, 'first_payment_amount' => 0, 'recurring_amount' => 0, 'recurring_cycles_limit' => 0];

    $this->put(route('admin.services.services.update', $service), $payload('admin'))->assertSessionHasErrors(['username' => 'This username is reserved on the server; choose another.']);
    $this->put(route('admin.services.services.update', $service), $payload('longname'))->assertSessionHasErrors(['username' => 'The username can be at most 6 characters on this server.']);
    $this->put(route('admin.services.services.update', $service), $payload('okname'))->assertSessionHasNoErrors();
});

test('a driver command timeout raises the job timeout and the lock TTL within the cap', function () {
    app(ProvisioningDriverRegistry::class)->register('strict_panel', fn () => strictPanel());
    $this->kit->priceBook->addProduct(34, 'Strict', driver: 'strict_panel');
    $strict = Service::factory()->create(['product_id' => 34]);
    $plain = Service::factory()->create();
    $settings = app(ServiceSettings::class);

    expect((new RunModuleCommand($strict->id, 'create'))->timeout)->toBe(150)
        ->and((new RunModuleCommand($plain->id, 'create'))->timeout)->toBe(60)
        ->and($settings->jobTimeoutSeconds(900))->toBe(180)
        ->and($settings->lockTtlSeconds(150))->toBe(170)
        ->and($settings->lockTtlSeconds(60))->toBe(80);
});

test('the longest job plus its lock slack stays below the queue retry_after', function () {
    $longest = app(ServiceSettings::class)->maxJobTimeoutSeconds() + ServiceSettings::LOCK_SLACK_SECONDS;

    foreach (['database', 'redis', 'beanstalkd'] as $connection) {
        expect($longest)->toBeLessThan((int) config("queue.connections.{$connection}.retry_after"));
    }
});

test('remote field options are merged across servers, keyed by server id, cached and refreshed on demand', function () {
    $driver = strictPanel(['a.panel.test' => ['starter' => 'starter (10 GB)', 'pro' => 'pro (50 GB)'], 'b.panel.test' => ['starter' => 'starter (10 GB)']]);
    app(ProvisioningDriverRegistry::class)->register('strict_panel', fn () => $driver);
    $a = Server::factory()->create(['driver' => 'strict_panel', 'hostname' => 'a.panel.test', 'name' => 'Web']);
    $b = Server::factory()->create(['driver' => 'strict_panel', 'hostname' => 'b.panel.test', 'name' => 'Web']);
    $down = Server::factory()->create(['driver' => 'strict_panel', 'hostname' => 'c.panel.test', 'name' => 'Down', 'password' => 'Down-Pass-77']);
    $drivers = app(ProvisioningDrivers::class);

    $options = $drivers->remoteOptions('strict_panel', 'package', [$a->id, $b->id, $down->id]);

    expect($options->options)->toBe(['starter' => 'starter (10 GB)', 'pro' => 'pro (50 GB)'])
        ->and($options->missingOn)->toBe(['pro' => [$b->id => 'Web']])
        ->and($options->unreachable)->toBe([$down->id => 'Down'])
        ->and($drivers->remoteOptions('strict_panel', 'unknown_field', [$a->id])->options)->toBe([])
        ->and(collect($drivers->all())->firstWhere('key', 'strict_panel')['config_fields'][0])->toMatchArray(['type' => 'select', 'options_source' => 'remote']);

    $calls = $driver->optionCalls;
    $drivers->remoteOptions('strict_panel', 'package', [$a->id, $b->id]);
    expect($driver->optionCalls)->toBe($calls);

    $drivers->remoteOptions('strict_panel', 'package', [$a->id, $b->id], refresh: true);
    expect($driver->optionCalls)->toBe($calls + 2);
});
