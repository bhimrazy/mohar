<?php

use App\Events\Services\ProvisioningFailed;
use App\Events\Services\ServiceActivated;
use App\Events\Services\ServiceSuspended;
use App\Events\Services\ServiceTerminated;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Enums\FillType;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\QueueStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Exceptions\InvalidStatusTransition;
use App\Modules\Services\Jobs\RunModuleCommand;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Support\Integrations\Redactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
});

/**
 * @param  array<int, array{0: int, 1: int}>  $servers  [active accounts, max accounts]
 * @return array{0: ServerGroup, 1: list<Server>}
 */
function serverGroupWith(FillType $fillType, array $servers): array
{
    $group = ServerGroup::factory()->create(['fill_type' => $fillType]);
    $created = [];

    foreach ($servers as $index => [$used, $max]) {
        $server = Server::factory()->create(['max_accounts' => $max]);
        $group->servers()->attach($server, ['sort_order' => $index]);
        Service::factory()->count($used)->create(['server_id' => $server->id]);
        $created[] = $server;
    }

    return [$group, $created];
}

test('creating a pending service picks a server, generates credentials and activates it', function () {
    Event::fake([ServiceActivated::class]);
    [$group, [$server]] = serverGroupWith(FillType::LeastFull, [[0, 10]]);
    $this->kit->priceBook->addProduct(11, 'Starter', serverGroupId: $group->id);
    $service = Service::factory()->pending()->create(['product_id' => 11, 'domain' => 'shop.example.com']);

    $outcome = app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    $service->refresh();
    expect($outcome->succeededNow())->toBeTrue()
        ->and($service->status)->toBe(ServiceStatus::Active)
        ->and($service->server_id)->toBe($server->id)
        ->and($service->username)->toBe('shop')
        ->and(strlen((string) $service->password))->toBe(16)
        ->and($service->activated_at)->not->toBeNull()
        ->and($this->kit->driver->callsFor('create'))->toHaveCount(1);
    Event::assertDispatched(ServiceActivated::class, fn (ServiceActivated $event) => $event->serviceId === $service->id);
    $this->assertDatabaseHas('activity_log', ['description' => 'Account created for shop.example.com', 'subject_id' => $service->id]);
});

test('least full picks the server with the lowest fill ratio', function () {
    [$group, [, $second]] = serverGroupWith(FillType::LeastFull, [[5, 10], [1, 10]]);
    $this->kit->priceBook->addProduct(11, 'Starter', serverGroupId: $group->id);
    $service = Service::factory()->pending()->create(['product_id' => 11]);

    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    expect($service->refresh()->server_id)->toBe($second->id);
});

test('fill sequentially skips full servers and fails without capacity', function () {
    [$group, [, $second]] = serverGroupWith(FillType::FillSequentially, [[2, 2], [0, 1]]);
    $this->kit->priceBook->addProduct(11, 'Starter', serverGroupId: $group->id);
    $first = Service::factory()->pending()->create(['product_id' => 11]);

    app(ModuleCommandDispatcher::class)->runNow($first, ModuleCommandName::Create);
    expect($first->refresh()->server_id)->toBe($second->id);

    $other = Service::factory()->pending()->create(['product_id' => 11]);
    $outcome = app(ModuleCommandDispatcher::class)->runNow($other, ModuleCommandName::Create);

    expect($outcome->message)->toBe('No server capacity')
        ->and($other->refresh()->status)->toBe(ServiceStatus::Pending)
        ->and(ModuleQueueItem::query()->where('subject_id', $other->id)->value('status'))->toBe(QueueStatus::Open);
});

test('inactive servers are never picked', function () {
    [$group, [$first]] = serverGroupWith(FillType::LeastFull, [[0, 10], [9, 10]]);
    $first->update(['is_active' => false]);
    $this->kit->priceBook->addProduct(11, 'Starter', serverGroupId: $group->id);
    $service = Service::factory()->pending()->create(['product_id' => 11]);

    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    expect($service->refresh()->server_id)->not->toBe($first->id);
});

test('a retryable failure is retried with backoff until it succeeds', function () {
    $this->kit->priceBook->addProduct(11, 'Starter');
    $server = Server::factory()->create(['is_default' => true]);
    $service = Service::factory()->pending()->create(['product_id' => 11, 'server_id' => $server->id]);
    $this->kit->driver->failNext('create', times: 2, retryable: true, message: 'Panel busy');

    RunModuleCommand::dispatch($service->id, 'create');

    $attempts = ModuleCommand::query()->where('service_id', $service->id)->orderBy('id')->get();
    expect($attempts->pluck('attempt')->all())->toBe([1, 2, 3])
        ->and($attempts->pluck('success')->all())->toBe([false, false, true])
        ->and($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and(ModuleQueueItem::query()->where('status', QueueStatus::Open->value)->exists())->toBeFalse();
});

test('a retryable failure schedules the next attempt with the configured delay', function () {
    Queue::fake();
    $this->kit->priceBook->addProduct(11, 'Starter');
    $service = Service::factory()->pending()->create(['product_id' => 11, 'server_id' => Server::factory()->create()->id]);
    $this->kit->driver->failNext('create', retryable: true);

    (new RunModuleCommand($service->id, 'create'))->handle(app(ExecuteModuleCommand::class));

    Queue::assertPushed(RunModuleCommand::class, fn (RunModuleCommand $job) => $job->attempt === 2 && $job->delay === 60 && $job->queue === 'provisioning');
});

test('retryable failures that exhaust all attempts end in the module queue', function () {
    Event::fake([ProvisioningFailed::class]);
    $this->kit->priceBook->addProduct(11, 'Starter');
    $service = Service::factory()->pending()->create(['product_id' => 11, 'server_id' => Server::factory()->create()->id]);
    $this->kit->driver->failNext('create', times: 5, retryable: true);

    RunModuleCommand::dispatch($service->id, 'create');

    expect(ModuleCommand::query()->where('service_id', $service->id)->count())->toBe(3)
        ->and(ModuleQueueItem::query()->where('subject_id', $service->id)->value('status'))->toBe(QueueStatus::Open);
    Event::assertDispatchedTimes(ProvisioningFailed::class, 1);
});

test('a non-retryable failure is logged once, queued for staff and leaves the status unchanged', function () {
    Event::fake([ProvisioningFailed::class]);
    $service = Service::factory()->onServer()->create();
    $this->kit->driver->failNext('suspend', message: 'Account locked');

    RunModuleCommand::dispatch($service->id, 'suspend', ['reason' => 'overdue']);

    expect(ModuleCommand::query()->where('service_id', $service->id)->count())->toBe(1)
        ->and($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and(ModuleQueueItem::query()->where('subject_id', $service->id)->first())
        ->status->toBe(QueueStatus::Open)
        ->last_error->toBe('Account locked');
    Event::assertDispatched(ProvisioningFailed::class, fn (ProvisioningFailed $event) => $event->serviceId === $service->id && $event->action === 'suspend');
});

test('a later successful run resolves the open queue entry', function () {
    $service = Service::factory()->onServer()->create();
    $this->kit->driver->failNext('suspend');
    RunModuleCommand::dispatch($service->id, 'suspend', ['reason' => 'overdue']);

    RunModuleCommand::dispatch($service->id, 'suspend', ['reason' => 'overdue']);

    expect(ModuleQueueItem::query()->where('subject_id', $service->id)->value('status'))->toBe(QueueStatus::Resolved)
        ->and($service->refresh()->status)->toBe(ServiceStatus::Suspended);
});

test('module logs never contain server or service passwords', function () {
    $server = Server::factory()->create(['password' => 'TopSecretPw!']);
    $service = Service::factory()->onServer($server)->create(['password' => 'ClientPw-123']);

    RunModuleCommand::dispatch($service->id, 'change_password', ['password' => 'NewPw-456789']);

    $log = ModuleCommand::query()->where('service_id', $service->id)->sole();
    $raw = json_encode([$log->request, $log->response, $log->error]);
    expect($raw)->not->toContain('TopSecretPw!')
        ->not->toContain('ClientPw-123')
        ->not->toContain('NewPw-456789')
        ->toContain(Redactor::MASK)
        ->and($service->refresh()->password)->toBe('NewPw-456789');
});

test('a command waiting for the per-service lock is re-queued without calling the driver', function () {
    Queue::fake();
    $service = Service::factory()->onServer()->create();
    $lock = Cache::lock(ExecuteModuleCommand::lockKey($service->id), 60);
    $lock->get();
    config(['services.module.lock_wait_seconds' => 1]);

    (new RunModuleCommand($service->id, 'terminate'))->handle(app(ExecuteModuleCommand::class));

    Queue::assertPushed(RunModuleCommand::class, fn (RunModuleCommand $job) => $job->lockRetries === 1 && $job->command === 'terminate' && $job->delay === 15);
    expect($this->kit->driver->callsFor('terminate'))->toBeEmpty();

    $lock->release();
    (new RunModuleCommand($service->id, 'terminate', lockRetries: 1))->handle(app(ExecuteModuleCommand::class));

    expect($this->kit->driver->callsFor('terminate'))->toHaveCount(1);
});

test('suspend and terminate queued for one service run one after the other', function () {
    $service = Service::factory()->onServer()->create();

    RunModuleCommand::dispatch($service->id, 'suspend', ['reason' => 'overdue']);
    RunModuleCommand::dispatch($service->id, 'terminate');

    expect(array_column($this->kit->driver->calls, 'command'))->toBe(['suspend', 'terminate'])
        ->and($service->refresh()->status)->toBe(ServiceStatus::Terminated)
        ->and(Cache::lock(ExecuteModuleCommand::lockKey($service->id), 1)->get())->toBeTrue();
});

test('a waiting command gives up after the configured lock retries and is reported', function () {
    Event::fake([ProvisioningFailed::class]);
    $service = Service::factory()->onServer()->create();
    Cache::lock(ExecuteModuleCommand::lockKey($service->id), 60)->get();
    config(['services.module.lock_wait_seconds' => 1, 'services.module.lock_retries' => 2]);

    (new RunModuleCommand($service->id, 'suspend', ['reason' => 'overdue'], lockRetries: 2))->handle(app(ExecuteModuleCommand::class));

    expect(ModuleQueueItem::query()->where('subject_id', $service->id)->value('kind'))->toBe('failure');
    Event::assertDispatched(ProvisioningFailed::class);
});

test('illegal transitions are rejected before any driver call', function () {
    $service = Service::factory()->status(ServiceStatus::Cancelled)->onServer()->create();

    expect(fn () => app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Suspend))
        ->toThrow(InvalidStatusTransition::class);
    expect($this->kit->driver->calls)->toBeEmpty();
});

test('queued commands that became illegal are skipped without a driver call', function () {
    $service = Service::factory()->suspended()->onServer()->create();

    RunModuleCommand::dispatch($service->id, 'suspend', ['reason' => 'overdue']);

    expect($this->kit->driver->callsFor('suspend'))->toBeEmpty();
});

test('suspending and terminating a parent cascades to its addons', function () {
    Event::fake([ServiceSuspended::class, ServiceTerminated::class]);
    $service = Service::factory()->onServer()->create();
    $addon = ServiceAddon::factory()->create(['service_id' => $service->id]);

    RunModuleCommand::dispatch($service->id, 'suspend', ['reason' => 'overdue']);
    expect($addon->refresh()->status)->toBe(ServiceStatus::Suspended);

    RunModuleCommand::dispatch($service->id, 'terminate');

    expect($service->refresh())->status->toBe(ServiceStatus::Terminated)
        ->termination_date->toDateString()->toBe(today()->toDateString())
        ->and($addon->refresh())->status->toBe(ServiceStatus::Cancelled)
        ->termination_date->toDateString()->toBe(today()->toDateString());
    Event::assertDispatched(ServiceSuspended::class, fn (ServiceSuspended $event) => $event->reason === 'overdue');
    Event::assertDispatched(ServiceTerminated::class);
});

test('the none driver changes status without a server', function () {
    $this->kit->priceBook->addProduct(11, 'Manual', driver: 'none');
    $service = Service::factory()->pending()->create(['product_id' => 11]);

    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    expect($service->refresh())->status->toBe(ServiceStatus::Active)->server_id->toBeNull()
        ->and(ModuleCommand::query()->where('service_id', $service->id)->value('driver'))->toBe('none');
});
