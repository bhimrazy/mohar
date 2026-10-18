<?php

use App\Enums\ReconcileStatus;
use App\Events\Services\ServiceActivated;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\VerifyUnknownOutcomes;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\QueueStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ModuleCommandDispatcher;
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

test('the check uses the username and domain the attempt sent, not later edits', function () {
    $service = unknownCpanelCreate();
    $service->update(['username' => 'edited1', 'domain' => 'edited-later.com']);
    Whm::fake(['listaccts' => Whm::accounts(['unix_startdate' => now()->getTimestamp()])]);

    $this->artisan('services:verify-unknown')->assertSuccessful();

    expect(Whm::params(Whm::sent('listaccts')[0]))->toMatchArray(['search' => 'example'])
        ->and($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($service->username)->toBe('example');
});
