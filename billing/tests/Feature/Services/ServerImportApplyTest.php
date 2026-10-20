<?php

use App\Events\Services\ServiceImported;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Import accounts, step 2 (cPanel spec §10, AC 27 and 28): staff tick, choose, review and confirm; each account is
 * matched and checked again against today when reviewed and applied, and becomes a service as the server holds it,
 * with no invoice, welcome mail or module command.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200, 'annually' => 12000], driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_basic']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    $this->chef = Client::factory()->create(['email' => 'someone-else@example.com', 'currency' => 'USD']);

    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
    actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
});

function applyReviewed(Server $server): void
{
    $digest = test()->get(route('admin.services.servers.import.review', $server))->inertiaProps('digest');
    test()->post(route('admin.services.servers.import.apply', $server), ['digest' => $digest])->assertSessionHasNoErrors()->assertRedirect(route('admin.services.servers.import.show', $server));
}

function chooseFor(Server $server, array $choice): void
{
    test()->put(route('admin.services.servers.import.select', $server), $choice)->assertSessionHasNoErrors();
}

/**
 * @return array<string, array<string, mixed>>
 */
function reviewRows(Server $server): array
{
    return collect(test()->get(route('admin.services.servers.import.review', $server))->assertOk()->inertiaProps('rows'))->keyBy('username')->all();
}

test('nothing is ticked by default; Select all proposed ticks only the proposed accounts, with their defaults', function () {
    $this->get(route('admin.services.servers.import.show', $this->server))->assertInertia(fn (Assert $page) => $page->where('included', 0));
    expect(reviewRows($this->server))->toBe([]);

    chooseFor($this->server, ['proposed' => true]);

    expect(reviewRows($this->server)['alpha']['decision'])->toMatchArray(['cycle' => 'monthly', 'next_due_date' => '2026-11-14', 'amount' => 1200, 'problems' => []])
        ->and(array_keys(reviewRows($this->server)))->toBe(['alpha']);
});

test('confirmed accounts become services as the server holds them, with no invoice, mail or module command, once', function () {
    Event::fake([ServiceImported::class]);
    Mail::fake();
    chooseFor($this->server, ['usernames' => ['alpha'], 'include' => true]);
    chooseFor($this->server, ['usernames' => ['bravo'], 'include' => true, 'client_id' => $this->chef->id, 'product_id' => 21, 'cycle' => 'annually', 'next_due_date' => '2027-01-31']);

    $this->get(route('admin.services.servers.import.review', $this->server))->assertInertia(fn (Assert $page) => $page
        ->component('admin/services/servers/import-review')
        ->has('rows', 2)
        ->where('totals', [['currency' => 'USD', 'cycle' => 'monthly', 'amount' => 1200, 'accounts' => 1], ['currency' => 'USD', 'cycle' => 'annually', 'amount' => 12000, 'accounts' => 1]]));

    applyReviewed($this->server);
    $import = ServerAccountImport::query()->sole();
    expect($import->only(['status', 'linked_count', 'proposed_count', 'selections', 'reviewed']))->toBe(['status' => 'ready', 'linked_count' => 2, 'proposed_count' => 0, 'selections' => null, 'reviewed' => null])
        ->and(collect($import->report)->every('imported'))->toBeTrue();

    $alpha = Service::query()->where('username', 'alpha')->sole();
    $bravo = Service::query()->where('username', 'bravo')->sole();
    expect($alpha)
        ->status->toBe(ServiceStatus::Active)
        ->client_id->toBe($this->anna->id)
        ->recurring_amount->toBe(1200)
        ->domain->toBe('alpha-books.com')
        ->password->toBeNull()
        ->registration_date->toDateString()->toBe('2023-11-14')
        ->next_due_date->toDateString()->toBe('2026-11-14')
        ->and($bravo)
        ->status->toBe(ServiceStatus::Suspended)
        ->suspension_reason->toBe('staff:Imported (suspended on the server: Abuse report)')
        ->cycle->value->toBe('annually')
        ->next_invoice_date->toDateString()->toBe('2027-01-31')
        ->driver_data->toMatchArray(['owner' => 'reseller1', 'plan' => 'reseller1_starter'])
        ->and($this->kit->invoicer->invoices)->toBe([])
        ->and(ModuleCommand::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('subject_id', $bravo->id)->value('properties'))->toMatchArray(['server' => 'Shared 1', 'username' => 'bravo', 'product' => 'Basic hosting'])
        ->and(ActivityLog::query()->where('description', 'Imported 2 accounts from server Shared 1')->exists())->toBeTrue();
    Mail::assertNothingSent();
    Event::assertDispatchedTimes(ServiceImported::class, 2);
    Event::assertDispatched(ServiceImported::class, fn (ServiceImported $event): bool => $event->serviceId === $bravo->id && $event->serverId === $this->server->id && $event->source === 'server_import');

    expect(Service::query()->count())->toBe(2);
    Event::assertDispatchedTimes(ServiceImported::class, 2);
});

test('apply imports exactly what was reviewed: a price changed since refuses it', function () {
    chooseFor($this->server, ['usernames' => ['alpha'], 'include' => true]);
    $digest = $this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('digest');
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1500], driver: 'cpanel', serverGroupId: ServerGroup::query()->value('id'), driverConfig: ['package' => 'root_basic']);

    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])
        ->assertSessionHasErrors(['import' => 'Review the import again: an account, client, product or price changed since you reviewed it.']);

    expect(Service::query()->count())->toBe(0)
        ->and(ServerAccountImport::query()->sole()->status)->toBe('ready');
});

test('a choice naming a product off this server or a past due date is refused', function () {
    $this->kit->priceBook->addProduct(30, 'Elsewhere', driver: 'cpanel', serverGroupId: 999, driverConfig: ['package' => 'root_basic']);

    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['bravo'], 'product_id' => 30, 'next_due_date' => '2026-10-01'])
        ->assertSessionHasErrors(['product_id', 'next_due_date']);
});
