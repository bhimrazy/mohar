<?php

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Enums\BillableType;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Enums\QueueStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Jobs\RunModuleCommand;
use App\Modules\Services\Models\CancellationRequest;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Support\Dashboard\Dashboard;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
});

test('guests and non-staff cannot reach admin service pages', function () {
    $this->get(route('admin.services.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create());
    $this->get(route('admin.services.index'))->assertForbidden();
});

test('staff without the services permission are forbidden', function (string $route) {
    actingAsStaff(['core.clients.*']);
    $service = Service::factory()->create();

    $this->get(route($route, $route === 'admin.services.services.show' ? $service : []))->assertForbidden();
})->with(['admin.services.index', 'admin.services.services.show', 'admin.services.servers.index', 'admin.services.cancellations.index', 'admin.services.module-log.index']);

test('the services list filters by status, server and client', function () {
    actingAsStaff(['services.services.view']);
    $server = Server::factory()->create();
    $client = Client::factory()->create();
    $match = Service::factory()->create(['client_id' => $client->id, 'server_id' => $server->id, 'status' => ServiceStatus::Suspended]);
    Service::factory()->create(['client_id' => $client->id, 'server_id' => $server->id]);
    Service::factory()->create(['status' => ServiceStatus::Suspended]);

    $this->get(route('admin.services.index', ['status' => 'suspended', 'server' => $server->id, 'client' => $client->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/services/index')
            ->has('services.data', 1)
            ->where('services.data.0.id', $match->id)
            ->where('services.data.0.client.id', $client->id)
            ->where('client.id', $client->id));
});

test('global search finds services by domain and username', function () {
    $user = actingAsStaff(['services.services.view']);
    $service = Service::factory()->create(['domain' => 'findme-shop.com', 'username' => 'zzuser']);

    $results = app(GlobalSearch::class)->search('FINDME', $user);
    $byUsername = app(GlobalSearch::class)->search('zzuser', $user);

    expect(collect($results)->firstWhere('group', 'Services')['results'][0]['href'])->toBe(route('admin.services.services.show', $service))
        ->and(collect($byUsername)->firstWhere('group', 'Services')['results'])->toHaveCount(1);
});

test('the detail page exposes commands and hides credentials until requested with permission', function () {
    actingAsStaff(['services.services.view', 'services.services.credentials']);
    $service = Service::factory()->onServer()->create(['password' => 'Visible-Pass1']);

    $this->get(route('admin.services.services.show', $service))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/services/show')
            ->where('service.id', $service->id)
            ->missing('service.password')
            ->missing('credentials')
            ->where('commands.0.value', 'suspend')
            ->where('can.credentials', true));

    $this->get(route('admin.services.services.show', $service), ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'admin/services/show', 'X-Inertia-Partial-Data' => 'credentials'])
        ->assertJsonPath('props.credentials.password', 'Visible-Pass1');
});

test('credentials stay hidden without the reveal permission', function () {
    actingAsStaff(['services.services.view']);
    $service = Service::factory()->onServer()->create();

    $this->get(route('admin.services.services.show', $service), ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'admin/services/show', 'X-Inertia-Partial-Data' => 'credentials'])
        ->assertJsonPath('props.credentials', null);
});

test('staff run module commands and see failures inline', function () {
    actingAsStaff(['services.services.view', 'services.services.provision']);
    $service = Service::factory()->onServer()->create();

    $this->post(route('admin.services.services.command', $service), ['command' => 'suspend', 'reason' => 'abuse report'])->assertRedirect();
    expect($service->refresh())->status->toBe(ServiceStatus::Suspended)->suspension_reason->toBe('staff:abuse report');

    $this->kit->driver->failNext('unsuspend', message: 'Panel offline');
    $this->post(route('admin.services.services.command', $service), ['command' => 'unsuspend'])->assertSessionHasErrors(['action']);
    expect($service->refresh()->status)->toBe(ServiceStatus::Suspended);

    $this->post(route('admin.services.services.command', $service), ['command' => 'suspend'])->assertSessionHasErrors(['action']);
});

test('module commands require the provision permission', function () {
    actingAsStaff(['services.services.view', 'services.services.manage']);
    $service = Service::factory()->onServer()->create();

    $this->post(route('admin.services.services.command', $service), ['command' => 'terminate'])->assertForbidden();
});

test('change password validates the new password', function () {
    actingAsStaff(['services.*']);
    $service = Service::factory()->onServer()->create();

    $this->post(route('admin.services.services.command', $service), ['command' => 'change_password'])->assertSessionHasErrors(['password']);
    $this->post(route('admin.services.services.command', $service), ['command' => 'reboot'])->assertSessionHasErrors(['command']);
});

test('staff can set a status without calling the module, with an audit entry', function () {
    actingAsStaff(['services.services.manage']);
    $service = Service::factory()->pending()->create();

    $this->post(route('admin.services.services.status', $service), ['status' => 'active', 'note' => 'created by hand'])->assertRedirect();

    expect($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($this->kit->driver->calls)->toBeEmpty();
    $this->assertDatabaseHas('activity_log', ['client_id' => $service->client_id, 'description' => 'Status set to active without module call']);

    $this->post(route('admin.services.services.status', $service), ['status' => 'pending'])->assertSessionHasErrors(['action']);
});

test('staff edit billing fields and changing the due date re-anchors', function () {
    actingAsStaff(['services.services.manage']);
    $service = Service::factory()->dueOn('2026-11-15')->create();

    $this->put(route('admin.services.services.update', $service), [
        'domain' => 'NEW-domain.com',
        'cycle' => 'monthly',
        'quantity' => 1,
        'first_payment_amount' => 1000,
        'recurring_amount' => 1599,
        'next_due_date' => '2026-11-30',
        'next_invoice_date' => '2026-11-30',
        'recurring_cycles_limit' => 0,
        'do_not_renew' => false,
        'terminate_at_period_end' => true,
        'suspend_days_override' => 3,
    ])->assertRedirect(route('admin.services.services.show', $service));

    expect($service->refresh())
        ->domain->toBe('new-domain.com')
        ->recurring_amount->toBe(1599)
        ->billing_anchor_day->toBe(30)
        ->terminate_at_period_end->toBeTrue()
        ->suspend_days_override->toBe(3);
});

test('service edits are validated', function () {
    actingAsStaff(['services.services.manage']);
    $service = Service::factory()->create();

    $this->put(route('admin.services.services.update', $service), ['cycle' => 'weekly', 'recurring_amount' => -5, 'quantity' => 0, 'next_due_date' => '2026-11-30', 'next_invoice_date' => '2026-11-01', 'dedicated_ip' => 'nope'])
        ->assertSessionHasErrors(['cycle', 'recurring_amount', 'quantity', 'next_invoice_date', 'dedicated_ip', 'first_payment_amount']);
});

test('staff order an upgrade for a client', function () {
    actingAsStaff(['services.services.manage']);
    $this->kit->priceBook->addProduct(1, 'Starter');
    $this->kit->priceBook->addProduct(2, 'Business', ['monthly' => 2000]);
    $this->kit->priceBook->allowUpgrade(1, 2);
    $service = Service::factory()->dueOn(today()->addDays(10))->create(['product_id' => 1]);

    $this->post(route('admin.services.services.change', $service), ['product_id' => 2, 'cycle' => 'monthly'])->assertRedirect();

    expect($this->kit->invoicer->invoicesFor(BillableType::ServiceUpgrade, $service->changes()->value('id')))->toHaveCount(1);
});

test('staff record a cancellation and approve or void it from the review list', function () {
    actingAsStaff(['services.services.view', 'services.cancellations.manage']);
    $service = Service::factory()->onServer()->create();

    $this->post(route('admin.services.services.cancel', $service), ['type' => 'end_of_period', 'reason' => 'Client asked by phone'])->assertRedirect();
    $request = CancellationRequest::query()->sole();
    expect($request)->by_staff->toBeTrue()->status->toBe(CancellationStatus::Open);

    $this->get(route('admin.services.cancellations.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/services/cancellations/index')->has('requests.data', 1)->where('requests.data.0.id', $request->id));

    $this->post(route('admin.services.cancellations.approve', $request))->assertRedirect();
    expect($service->refresh()->status)->toBe(ServiceStatus::Cancelled)->and($request->refresh()->status)->toBe(CancellationStatus::Processed);

    $this->post(route('admin.services.cancellations.void', $request))->assertSessionHasErrors(['action']);
});

test('deleting a service is local only', function () {
    actingAsStaff(['services.services.manage']);
    $service = Service::factory()->onServer()->create();

    $this->delete(route('admin.services.services.destroy', $service))->assertRedirect(route('admin.services.index'));

    $this->assertSoftDeleted($service);
    expect($this->kit->driver->calls)->toBeEmpty();
});

test('the module queue can be retried, resolved and dismissed', function () {
    actingAsStaff(['services.module_log.view', 'services.module_log.manage']);
    $service = Service::factory()->onServer()->create();
    $this->kit->driver->failNext('suspend');
    RunModuleCommand::dispatch($service->id, 'suspend', ['reason' => 'overdue']);
    $item = ModuleQueueItem::query()->sole();

    $this->get(route('admin.services.module-log.index', ['tab' => 'queue']))
        ->assertInertia(fn (Assert $page) => $page->component('admin/services/module-log/index')->where('openCount', 1)->has('queue.data', 1));
    $this->get(route('admin.services.module-log.index', ['result' => 'failed']))
        ->assertInertia(fn (Assert $page) => $page->has('log.data', 1)->where('log.data.0.success', false));

    $this->post(route('admin.services.module-log.retry', $item))->assertRedirect();
    expect($item->refresh()->status)->toBe(QueueStatus::Resolved)
        ->and($service->refresh()->suspension_reason)->toBe('overdue');

    $other = ModuleQueueItem::query()->create(['subject_type' => 'service', 'subject_id' => $service->id, 'command' => 'unsuspend', 'status' => 'open']);
    $this->post(route('admin.services.module-log.dismiss', $other))->assertRedirect();
    expect($other->refresh()->status)->toBe(QueueStatus::Dismissed);
});

test('module queue actions require the manage permission', function () {
    actingAsStaff(['services.module_log.view']);
    $item = ModuleQueueItem::query()->create(['subject_type' => 'service', 'subject_id' => Service::factory()->create()->id, 'command' => 'create', 'status' => 'open']);

    $this->post(route('admin.services.module-log.resolve', $item))->assertForbidden();
});

test('every permission checked by the module is registered', function () {
    $registry = app(PermissionRegistry::class);

    foreach (['view', 'manage', 'provision', 'credentials'] as $action) {
        expect($registry->exists("services.services.{$action}"))->toBeTrue();
    }

    foreach (['services.servers.view', 'services.servers.manage', 'services.cancellations.manage', 'services.module_log.view', 'services.module_log.manage', 'services.settings.manage'] as $permission) {
        expect($registry->exists($permission))->toBeTrue();
    }
});

test('dashboard cards, navigation and merge fields are registered', function () {
    $user = actingAsStaff(['services.*']);
    Service::factory()->pending()->create();
    $service = Service::factory()->onServer()->create();

    $cards = collect(app(Dashboard::class)->resolve('admin', $user))->keyBy('key');
    $fields = app(MergeFieldRegistry::class)->resolve('service', $service->id);

    expect($cards['services.active']['value'])->toBe(1)
        ->and($cards['services.pending']['value'])->toBe(1)
        ->and($cards['services.failed']['value'])->toBe(0)
        ->and($fields)->toMatchArray(['service_id' => $service->id, 'client_id' => $service->client_id, 'domain' => $service->domain])
        ->and($fields)->not->toHaveKey('password');

    $this->get(route('admin.services.index'))->assertInertia(fn (Assert $page) => $page->where('navigation', fn ($groups) => collect($groups)->firstWhere('title', 'Services')['items'][0]['title'] === 'Services'));
});
