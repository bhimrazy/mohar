<?php

use App\Contracts\Services\Provisioning\ProvidesFieldOptions;
use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Contracts\Services\ProvisioningDrivers;
use App\Contracts\Services\ServiceRegistry;
use App\Data\Integrations\SettingField;
use App\Data\Services\Provisioning\DriverMetadata;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Data\Services\Provisioning\ServerConnection;
use App\Data\Services\Provisioning\UsernamePolicy;
use App\Enums\FieldType;
use App\Events\Services\ProvisioningFailed;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\ApplyServiceChange;
use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Enums\ChangeKind;
use App\Modules\Services\Enums\ChangeStatus;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Jobs\RunModuleCommand;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceChange;
use App\Modules\Services\Models\UsageSnapshot;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Modules\Services\Support\ServiceSettings;
use App\Modules\Services\Support\UsernameGenerator;
use App\Support\Integrations\OutboundAddressGuard;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Fixtures\Extensions\AcmePanel\AcmePanelServiceProvider;

beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->app->register(AcmePanelServiceProvider::class);
    // Package drivers connect through the kernel guard; *.acme.test is a public address here.
    $this->app->instance(OutboundAddressGuard::class, new OutboundAddressGuard(fn (string $host): array => str_ends_with($host, '.acme.test') ? ['93.184.216.34'] : []));
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
    $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

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
        ->and(collect($drivers->all())->firstWhere('key', 'strict_panel')['fields'][0])->toMatchArray(['type' => 'select', 'options_source' => 'remote']);

    $calls = $driver->optionCalls;
    $drivers->remoteOptions('strict_panel', 'package', [$a->id, $b->id]);
    expect($driver->optionCalls)->toBe($calls);

    $drivers->remoteOptions('strict_panel', 'package', [$a->id, $b->id], refresh: true);
    expect($driver->optionCalls)->toBe($calls + 2);
});

function pendingChange(Service $service, int $fromProductId, int $toProductId): ServiceChange
{
    $snapshot = fn (int $productId, int $amount): array => ['product_id' => $productId, 'product_name' => "Product {$productId}", 'cycle' => 'monthly', 'recurring_amount' => $amount, 'next_due_date' => null, 'billing_anchor_day' => null];

    return ServiceChange::query()->create([
        'service_id' => $service->id, 'kind' => ChangeKind::Product, 'from_snapshot' => $snapshot($fromProductId, 1000), 'to_snapshot' => $snapshot($toProductId, 2000),
        'currency' => $service->currency, 'credit_amount' => 0, 'charge_amount' => 0, 'amount_due' => 0, 'status' => ChangeStatus::PendingPayment,
    ]);
}

test('a driver that throws during the call leaves the outcome unknown, and a redispatched create does not run again', function () {
    $calls = 0;
    app(ProvisioningDriverRegistry::class)->register('throwing_panel', function () use (&$calls): ProvisioningDriver {
        return new class($calls) implements ProvisioningDriver
        {
            public function __construct(private int &$calls) {}

            public function key(): string
            {
                return 'throwing_panel';
            }

            public function name(): string
            {
                return 'Throwing panel';
            }

            public function metadata(): DriverMetadata
            {
                return new DriverMetadata(requiresServer: true);
            }

            public function create(ProvisioningContext $context): DriverResult
            {
                $this->calls++;

                throw new RuntimeException('Malformed JSON in the answer after the account request was sent.');
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
    });
    $this->kit->priceBook->addProduct(35, 'Throwing', driver: 'throwing_panel');
    $service = Service::factory()->pending()->create(['product_id' => 35, 'server_id' => Server::factory()->create(['driver' => 'throwing_panel'])->id]);

    RunModuleCommand::dispatch($service->id, 'create');
    RunModuleCommand::dispatch($service->id, 'create');

    expect($calls)->toBe(1)
        ->and(ModuleCommand::query()->where('service_id', $service->id)->orderBy('id')->first()->status)->toBe(ModuleCommand::UNKNOWN)
        ->and(ModuleQueueItem::query()->where('subject_id', $service->id)->sole()->kind)->toBe(ModuleQueueItem::KIND_VERIFY)
        ->and($service->refresh()->status)->toBe(ServiceStatus::Pending);
});

test('an upgrade on a driver without package changes stays applied and is noted, not failed', function () {
    Event::fake([ProvisioningFailed::class]);
    $server = Server::factory()->create(['driver' => 'acme_panel']);
    $this->kit->priceBook->addProduct(36, 'Acme small', driver: 'acme_panel');
    $this->kit->priceBook->addProduct(37, 'Acme large', driver: 'acme_panel');
    $service = Service::factory()->onServer($server)->create(['product_id' => 36]);
    $change = pendingChange($service, 36, 37);

    app(ApplyServiceChange::class)->handle($change->id);

    expect($change->refresh()->status)->toBe(ChangeStatus::Applied)
        ->and(ModuleCommand::query()->where('service_id', $service->id)->exists())->toBeFalse()
        ->and(ModuleQueueItem::query()->where('subject_id', $service->id)->exists())->toBeFalse();
    $this->assertDatabaseHas('activity_log', ['subject_id' => $service->id, 'description' => "Package change for {$service->label()} not sent to the server: this driver can't change packages"]);
    Event::assertNotDispatched(ProvisioningFailed::class);
    Http::assertNothingSent();
});

test('an upgrade on a driver with package changes is sent to the server', function () {
    $service = Service::factory()->onServer()->create(['product_id' => 38]);
    $this->kit->priceBook->addProduct(38, 'Small');
    $this->kit->priceBook->addProduct(39, 'Large');
    $change = pendingChange($service, 38, 39);

    app(ApplyServiceChange::class)->handle($change->id);

    expect($this->kit->driver->callsFor('change_package'))->toHaveCount(1)
        ->and($this->kit->driver->callsFor('change_package')[0]['args']['from_product'])->toBe(38);
});

test('manual services keep free-form usernames: only a declared driver policy constrains them', function () {
    actingAsStaff(['services.services.view', 'services.services.manage']);
    $service = Service::factory()->create(['server_id' => null]);

    $this->put(route('admin.services.services.update', $service), ['username' => 'Administrator', 'cycle' => $service->cycle->value, 'quantity' => 1, 'first_payment_amount' => 0, 'recurring_amount' => 0, 'recurring_cycles_limit' => 0])
        ->assertSessionHasNoErrors();

    expect($service->refresh()->username)->toBe('Administrator');
});

test('a broken package driver does not stop automation for the other services', function () {
    app(ProvisioningDriverRegistry::class)->register('broken_panel', fn () => new stdClass);
    $this->kit->priceBook->addProduct(40, 'Broken', driver: 'broken_panel');
    $brokenServer = Server::factory()->create(['driver' => 'broken_panel']);
    Service::factory()->dueOn('2026-11-15')->onServer($brokenServer)->create(['product_id' => 40]);
    $healthy = Service::factory()->dueOn('2026-11-15')->onServer()->create();
    $this->kit->driver->rememberAccount((int) $healthy->server_id, (string) $healthy->username);

    $this->artisan('automation:run', ['--date' => '2026-11-15', '--only' => 'services.renewals'])->assertSuccessful();
    $this->artisan('automation:run', ['--date' => '2026-11-22', '--only' => 'services.suspensions', '--force' => true])->assertSuccessful();
    $this->artisan('automation:run', ['--date' => '2026-11-22', '--only' => 'services.usage-sync', '--force' => true])->assertSuccessful();

    expect($healthy->refresh()->status)->toBe(ServiceStatus::Suspended)
        ->and(UsageSnapshot::query()->where('service_id', $healthy->id)->exists())->toBeTrue();
});

test('staff "run now" queues commands whose driver needs longer than a page request', function () {
    Queue::fake();
    app(ProvisioningDriverRegistry::class)->register('strict_panel', fn () => strictPanel());
    $this->kit->priceBook->addProduct(42, 'Strict', driver: 'strict_panel');
    $service = Service::factory()->onServer(Server::factory()->create(['driver' => 'strict_panel']))->create(['product_id' => 42]);

    $outcome = app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Suspend);

    expect($outcome->status)->toBe('queued');
    Queue::assertPushed(RunModuleCommand::class, fn (RunModuleCommand $job) => $job->serviceId === $service->id && $job->timeout === 150);
});

test('after an unknown create, a failed job does not downgrade the verify item or let create run again', function () {
    $service = Service::factory()->pending()->create(['server_id' => Server::factory()->create()->id]);
    $this->kit->driver->failNext('create', message: 'Read timed out after sending', unknown: true);

    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);
    (new RunModuleCommand($service->id, 'create'))->failed(new RuntimeException('Worker lost'));
    RunModuleCommand::dispatch($service->id, 'create');
    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    expect($this->kit->driver->callsFor('create'))->toHaveCount(1)
        ->and(ModuleQueueItem::query()->where('subject_id', $service->id)->sole())
        ->kind->toBe(ModuleQueueItem::KIND_VERIFY)
        ->last_error->toStartWith('Outcome unknown');
});

test('a job redelivered after a worker timeout during create never calls create again', function () {
    $service = Service::factory()->pending()->create(['server_id' => Server::factory()->create()->id]);
    $this->kit->driver->whileRunning = function (string $command): void {
        if ($command === 'create') {
            throw new TimeoutExceededException('The job has timed out.');
        }
    };

    RunModuleCommand::dispatch($service->id, 'create');
    $this->kit->driver->whileRunning = null;
    (new RunModuleCommand($service->id, 'create'))->failed(new MaxAttemptsExceededException('Redelivered beyond its tries.'));
    RunModuleCommand::dispatch($service->id, 'create');

    expect($this->kit->driver->callsFor('create'))->toHaveCount(1)
        ->and(ModuleQueueItem::query()->where('subject_id', $service->id)->sole()->kind)->toBe(ModuleQueueItem::KIND_VERIFY)
        ->and($service->refresh()->status)->toBe(ServiceStatus::Pending);
});

test('provisioning a long-running driver on order acceptance reports it as queued', function () {
    Queue::fake();
    app(ProvisioningDriverRegistry::class)->register('strict_panel', fn () => strictPanel());
    $this->kit->priceBook->addProduct(43, 'Strict', driver: 'strict_panel');
    $service = Service::factory()->pending()->create(['product_id' => 43, 'server_id' => Server::factory()->create(['driver' => 'strict_panel'])->id]);

    $result = app(ServiceRegistry::class)->provision($service->id);

    expect($result->success)->toBeFalse()
        ->and($result->pending)->toBeTrue()
        ->and($result->message)->not->toContain('fail');
    Queue::assertPushed(RunModuleCommand::class);
});
