<?php

use App\Data\Services\Provisioning\CommandLookup;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Enums\ReconcileStatus;
use App\Events\Services\ProvisioningFailed;
use App\Events\Services\ServiceActivated;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Actions\VerifyUnknownOutcomes;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CertificateInspector;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CpanelDriver;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\QueueStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Support\Services\ProvisioningDriverRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * The verify sweep is generic host code (ReconcilesCommands); these tests drive it with the cPanel driver over
 * recorded WHM answers, which is the case the sweep was built for.
 */

/**
 * A cPanel create that timed out: the service is pending with an open verify item.
 */
function unknownCpanelCreate(): Service
{
    Currency::factory()->create();
    $kit = ServicesTestKit::bind();
    $server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $kit->priceBook->addProduct(41, 'Shared Basic', driver: 'cpanel', driverConfig: ['package' => 'root_basic']);
    $service = Service::factory()->pending()->create(['product_id' => 41, 'server_id' => $server->id, 'domain' => 'example-shop.com', 'username' => 'example', 'password' => Whm::PASSWORD]);

    Whm::fake(['createacct' => Http::failedConnection('cURL error 28: Operation timed out after 50001 milliseconds with 0 bytes received')]);
    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    return $service;
}

function verifyItem(Service $service): ModuleQueueItem
{
    return ModuleQueueItem::query()->where('subject_id', $service->id)->where('command', 'create')->sole();
}

test('a create found on the server with our domain and owner completes on the next sweep', function () {
    Event::fake([ServiceActivated::class]);
    $service = unknownCpanelCreate();
    expect(verifyItem($service)->kind)->toBe(ModuleQueueItem::KIND_VERIFY);

    Whm::fake(['listaccts' => Whm::accounts(['unix_startdate' => now()->getTimestamp()])]);
    $this->artisan('services:verify-unknown')->assertSuccessful();

    expect($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and(verifyItem($service)->status)->toBe(QueueStatus::Resolved)
        ->and(ModuleCommand::query()->where('service_id', $service->id)->where('status', ModuleCommand::UNKNOWN)->exists())->toBeFalse()
        ->and(Whm::sent('createacct'))->toBeEmpty();
    Event::assertDispatched(ServiceActivated::class);
});

test('a lookup that finds the username under another domain or owner leaves the item open as a conflict and sends no createacct', function () {
    $service = unknownCpanelCreate();
    Whm::fake(['listaccts' => 'listaccts_foreign']);

    $this->artisan('services:verify-unknown')->assertSuccessful();

    $item = verifyItem($service);
    expect($item->status)->toBe(QueueStatus::Open)
        ->and($item->lookup_status)->toBe(ReconcileStatus::Conflict)
        ->and($item->lookup_message)->toContain('belongs to an account (owner reseller9, domain other-site.net')
        ->and(app(VerifyUnknownOutcomes::class)->runAgainBlockedReason($item))->toContain('conflicting account')
        ->and(Whm::sent('createacct'))->toBeEmpty()
        ->and($service->refresh()->status)->toBe(ServiceStatus::Pending);
});

test('"Create again" waits until the account has been absent for 10 minutes, and is never run automatically', function () {
    actingAsStaff(['services.module_log.view', 'services.module_log.manage']);
    $service = unknownCpanelCreate();
    $item = verifyItem($service);
    Whm::fake(['listaccts' => 'listaccts_empty']);

    $this->post(route('admin.services.module-log.check', $item))->assertSessionHasNoErrors();
    expect($item->refresh()->lookup_status)->toBe(ReconcileStatus::InProgress);
    $this->post(route('admin.services.module-log.retry', $item))->assertSessionHasErrors('action');
    expect(Whm::sent('createacct'))->toBeEmpty();

    $this->travel(11)->minutes();
    $this->artisan('services:verify-unknown')->assertSuccessful();
    expect($item->refresh()->lookup_status)->toBe(ReconcileStatus::NotExecuted)
        ->and(Whm::sent('createacct'))->toBeEmpty()
        ->and($service->refresh()->status)->toBe(ServiceStatus::Pending);

    $this->get(route('admin.services.module-log.index', ['tab' => 'queue']))
        ->assertInertia(fn ($page) => $page->where('queue.data.0.retry_blocked', null)->where('queue.data.0.can_check', true));
    $this->post(route('admin.services.module-log.retry', $item))->assertSessionHasNoErrors();
    expect(Whm::sent('createacct'))->toHaveCount(1)
        ->and($service->refresh()->status)->toBe(ServiceStatus::Active);
});

test('the sweep stops checking an hour after the attempt; staff can still check on demand', function () {
    $service = unknownCpanelCreate();
    $createdAt = now()->getTimestamp();
    $this->travel(61)->minutes();
    Whm::fake(['listaccts' => Whm::accounts(['unix_startdate' => $createdAt])]);

    $this->artisan('services:verify-unknown')->expectsOutputToContain('0 unknown outcomes checked')->assertSuccessful();
    expect(verifyItem($service)->status)->toBe(QueueStatus::Open);

    actingAsStaff(['services.module_log.view', 'services.module_log.manage']);
    $this->post(route('admin.services.module-log.check', verifyItem($service)))->assertSessionHasNoErrors();
    expect(verifyItem($service)->status)->toBe(QueueStatus::Resolved);
});

test('checking needs the module log manage permission', function () {
    $service = unknownCpanelCreate();
    actingAsStaff(['services.module_log.view']);

    $this->post(route('admin.services.module-log.check', verifyItem($service)))->assertForbidden();
});

test('a service edited or moved after the attempt is a conflict for staff, and its new server is never asked', function (Closure $edit, string $message) {
    $service = unknownCpanelCreate();
    $edit($service);
    Whm::fake(['listaccts' => 'listaccts_empty']);
    $this->travel(11)->minutes();

    $this->artisan('services:verify-unknown')->assertSuccessful();

    $item = verifyItem($service);
    expect($item->lookup_status)->toBe(ReconcileStatus::Conflict)
        ->and($item->lookup_message)->toContain($message)
        ->and(Whm::sent('listaccts'))->toBeEmpty()
        ->and(app(VerifyUnknownOutcomes::class)->runAgainBlockedReason($item))->not->toBeNull();
})->with([
    'moved to another server' => [fn (Service $service) => $service->update(['server_id' => Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 2', 'hostname' => 'whm2.example.test'])->id]), 'check Shared 1 by hand'],
    'username or domain edited' => [fn (Service $service) => $service->update(['username' => 'edited1', 'domain' => 'edited-later.com']), 'changed after this attempt'],
]);

test('a check answers for one attempt: a Create again that times out needs a fresh check before another', function () {
    actingAsStaff(['services.module_log.view', 'services.module_log.manage']);
    $service = unknownCpanelCreate();
    $item = verifyItem($service);
    $this->travel(11)->minutes();
    Whm::fake(['listaccts' => 'listaccts_empty']);
    $this->post(route('admin.services.module-log.check', $item))->assertSessionHasNoErrors();
    expect($item->refresh()->lookup_status)->toBe(ReconcileStatus::NotExecuted);

    // Create again times out too: a new unknown attempt, so the earlier answer no longer applies.
    Whm::fake(['createacct' => Http::failedConnection('cURL error 28: Operation timed out after 50001 milliseconds with 0 bytes received')]);
    $this->post(route('admin.services.module-log.retry', $item))->assertSessionHasErrors('action');

    expect($item->refresh()->lookup_status)->toBeNull()
        ->and($item->kind)->toBe(ModuleQueueItem::KIND_VERIFY);
    $this->post(route('admin.services.module-log.retry', $item))->assertSessionHasErrors('action');
    expect(Whm::sent('createacct'))->toHaveCount(1);
});

test('an old "not on the server" answer does not open Create again', function () {
    $service = unknownCpanelCreate();
    $this->travel(11)->minutes();
    Whm::fake(['listaccts' => 'listaccts_empty']);
    app(VerifyUnknownOutcomes::class)->check(verifyItem($service));
    $this->travel(16)->minutes();

    expect(app(VerifyUnknownOutcomes::class)->runAgainBlockedReason(verifyItem($service)))->toContain('more than 15 minutes old');
});

test('a stale check never settles twice: double Check now, a dismissal or a Create again in between', function (Closure $between) {
    $service = unknownCpanelCreate();
    Event::fake([ServiceActivated::class, ProvisioningFailed::class]);
    $stale = verifyItem($service);
    Whm::fake(['listaccts' => Whm::accounts(['unix_startdate' => now()->getTimestamp()])]);

    $between($stale, $this);
    app(VerifyUnknownOutcomes::class)->check($stale);

    expect(ModuleQueueItem::query()->where('subject_id', $service->id)->where('kind', ModuleQueueItem::KIND_RECONCILE)->exists())->toBeFalse()
        ->and(ModuleCommand::query()->where('service_id', $service->id)->where('status', ModuleCommand::SUCCEEDED)->count())->toBeLessThanOrEqual(1);
    Event::assertNotDispatched(ProvisioningFailed::class);
    Event::assertDispatchedTimes(ServiceActivated::class, $service->refresh()->status === ServiceStatus::Active ? 1 : 0);
})->with([
    'a check already settled it' => [fn (ModuleQueueItem $item) => app(VerifyUnknownOutcomes::class)->check(ModuleQueueItem::query()->findOrFail($item->id))],
    'staff dismissed it' => [function (ModuleQueueItem $item, $test) {
        actingAsStaff(['services.module_log.view', 'services.module_log.manage']);
        $test->post(route('admin.services.module-log.dismiss', $item));
    }],
    'a Create again finished first' => [function (ModuleQueueItem $item, $test) {
        actingAsStaff(['services.module_log.view', 'services.module_log.manage']);
        $test->travel(11)->minutes();
        Whm::fake(['listaccts' => 'listaccts_empty']);
        $test->post(route('admin.services.module-log.check', $item));
        Whm::fake();
        $test->post(route('admin.services.module-log.retry', $item))->assertSessionHasNoErrors();
        Whm::fake(['listaccts' => Whm::accounts(['unix_startdate' => now()->getTimestamp()])]);
    }],
]);

test('staff dismissing during a check leaves the service pending and sends no welcome', function () {
    Event::fake([ServiceActivated::class]);
    $service = unknownCpanelCreate();
    $stale = verifyItem($service);
    actingAsStaff(['services.module_log.view', 'services.module_log.manage']);
    $this->post(route('admin.services.module-log.dismiss', $stale));
    Whm::fake(['listaccts' => Whm::accounts(['unix_startdate' => now()->getTimestamp()])]);

    expect(app(VerifyUnknownOutcomes::class)->check($stale))->toBeNull()
        ->and($service->refresh()->status)->toBe(ServiceStatus::Pending);
    Event::assertNotDispatched(ServiceActivated::class);
});

test('a service busy with another action is skipped by the sweep and Check now says so, never an error page', function () {
    config(['services.module.lock_wait_seconds' => 1]);
    actingAsStaff(['services.module_log.view', 'services.module_log.manage']);
    $busy = unknownCpanelCreate();
    $other = Service::factory()->pending()->create(['product_id' => 41, 'server_id' => $busy->server_id, 'domain' => 'second-shop.com', 'username' => 'second', 'password' => Whm::PASSWORD]);
    Whm::fake(['createacct' => Http::failedConnection('cURL error 28: Operation timed out after 50001 milliseconds with 0 bytes received')]);
    app(ModuleCommandDispatcher::class)->runNow($other, ModuleCommandName::Create);
    Whm::fake(['listaccts' => fn (Request $request) => Http::response(Whm::accounts([
        'user' => Whm::params($request)['search'], 'domain' => Whm::params($request)['search'] === 'second' ? 'second-shop.com' : 'example-shop.com', 'unix_startdate' => now()->getTimestamp(),
    ]))]);
    $lock = Cache::lock(ExecuteModuleCommand::lockKey($busy->id), 60);
    $lock->get();

    $this->artisan('services:verify-unknown')->assertSuccessful();
    expect(verifyItem($busy)->lookup_message)->toStartWith('Busy:')
        ->and($other->refresh()->status)->toBe(ServiceStatus::Active);

    $this->post(route('admin.services.module-log.check', verifyItem($busy)))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message) => str_starts_with($message, 'Busy:'));
    $lock->release();
});

test('Check now is throttled per staff member', function () {
    actingAsStaff(['services.module_log.view', 'services.module_log.manage']);
    $item = verifyItem(unknownCpanelCreate());
    Whm::fake(['listaccts' => 'listaccts_empty']);

    foreach (range(1, 6) as $ignored) {
        $this->post(route('admin.services.module-log.check', $item))->assertRedirect();
    }

    $this->post(route('admin.services.module-log.check', $item))->assertTooManyRequests();
});

test('a "not on the server" answer opens Create again once, and the create re-checks it under the lock', function () {
    actingAsStaff(['services.module_log.view', 'services.module_log.manage']);
    $service = unknownCpanelCreate();
    $item = verifyItem($service);
    $checked = ModuleCommand::query()->where('service_id', $service->id)->where('status', ModuleCommand::UNKNOWN)->value('id');
    $this->travel(11)->minutes();
    Whm::fake(['listaccts' => 'listaccts_empty']);
    app(VerifyUnknownOutcomes::class)->check($item);

    // The first click uses the answer up; a second click (a double-click, or a colleague) is refused at once.
    expect(app(VerifyUnknownOutcomes::class)->consumeRunAgain($item->refresh()))->toBe($checked)
        ->and(app(VerifyUnknownOutcomes::class)->consumeRunAgain($item->refresh()))->toBeNull();

    // The first click's create times out: a newer attempt of unknown outcome.
    Whm::fake(['createacct' => Http::failedConnection('cURL error 28: Operation timed out after 50001 milliseconds with 0 bytes received')]);
    app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Create, ['verified' => true, 'checked_attempt' => $checked]);

    // A job queued for the earlier answer (it passed the gate before the timeout) now runs: nothing is sent.
    $outcome = app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Create, ['verified' => true, 'checked_attempt' => $checked]);

    expect($outcome->message)->toContain('newer create attempt')
        ->and(Whm::sent('createacct'))->toHaveCount(1)
        ->and(verifyItem($service)->kind)->toBe(ModuleQueueItem::KIND_VERIFY)
        ->and(verifyItem($service)->status)->toBe(QueueStatus::Open);
});

test('a create settled by the check stores what a completed create stores', function () {
    $service = unknownCpanelCreate();
    Whm::fake(['listaccts' => Whm::accounts(['unix_startdate' => now()->getTimestamp(), 'ip' => '203.0.113.77', 'plan' => 'root_basic'])]);

    $this->artisan('services:verify-unknown')->assertSuccessful();

    expect($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($service->driver_data)->toMatchArray(['ip' => '203.0.113.77', 'plan' => 'root_basic', 'owner' => 'root']);
});

test('ignoring the entry cancels a Create again that was already requested', function () {
    actingAsStaff(['services.module_log.view', 'services.module_log.manage']);
    $service = unknownCpanelCreate();
    $item = verifyItem($service);
    $this->travel(11)->minutes();
    Whm::fake(['listaccts' => 'listaccts_empty']);
    app(VerifyUnknownOutcomes::class)->check($item);
    $checked = app(VerifyUnknownOutcomes::class)->consumeRunAgain($item->refresh());

    $this->post(route('admin.services.module-log.dismiss', $item));
    $outcome = app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Create, ['verified' => true, 'checked_attempt' => $checked]);

    expect($outcome->message)->toContain('entry was closed')
        ->and(Whm::sent('createacct'))->toBeEmpty()
        ->and($service->refresh()->status)->toBe(ServiceStatus::Pending);
});

test('settling stores the account data but never the username or password a driver reports', function () {
    $service = unknownCpanelCreate();
    $driver = new class(app(CertificateInspector::class)) extends CpanelDriver
    {
        public function reconcile(ProvisioningContext $context, string $command, CarbonImmutable $attemptedAt): CommandLookup
        {
            return new CommandLookup(ReconcileStatus::Executed, data: ['username' => 'hijack1', 'password' => 'Other-Pass-1', 'driver_data' => ['plan' => 'root_basic']]);
        }
    };
    app(ProvisioningDriverRegistry::class)->register('cpanel', fn () => $driver, replace: true);

    $this->artisan('services:verify-unknown')->assertSuccessful();

    expect($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($service->username)->toBe('example')
        ->and($service->password)->toBe(Whm::PASSWORD)
        ->and($service->driver_data['plan'])->toBe('root_basic');
});
