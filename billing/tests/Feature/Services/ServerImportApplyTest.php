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
        ->where('totals', [['currency' => 'USD', 'amount' => 13200]]));

    $this->post(route('admin.services.servers.import.apply', $this->server))
        ->assertRedirect(route('admin.services.servers.import.show', $this->server))
        ->assertSessionHas('inertia.flash_data.importReport', fn (array $report): bool => count($report) === 2 && collect($report)->every('imported'));

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

    chooseFor($this->server, ['usernames' => ['alpha', 'bravo'], 'include' => true]);
    $this->post(route('admin.services.servers.import.apply', $this->server))
        ->assertSessionHas('inertia.flash_data.importReport', fn (array $report): bool => collect($report)->every(fn (array $line): bool => ! $line['imported']));
    expect(Service::query()->count())->toBe(2);
    Event::assertDispatchedTimes(ServiceImported::class, 2);
});

test('apply matches and checks again: a reseller account needs a chosen client, a client closed since and a service created since keep accounts out', function () {
    chooseFor($this->server, ['usernames' => ['alpha', 'bravo', 'charlie'], 'include' => true]);
    $this->anna->update(['status' => 'closed']);
    Service::factory()->create(['server_id' => $this->server->id, 'username' => 'charlie', 'status' => ServiceStatus::Active]);

    $this->post(route('admin.services.servers.import.apply', $this->server))
        ->assertSessionHas('inertia.flash_data.importReport', function (array $report): bool {
            $messages = collect($report)->pluck('message', 'username');

            return str_contains($messages['alpha'], 'Choose a client.')
                && str_contains($messages['bravo'], 'Owned by another WHM user: choose its client yourself.')
                && str_contains($messages['charlie'], 'A live service on this server already holds this account.');
        });

    expect(Service::query()->where('username', '!=', 'charlie')->count())->toBe(0);
});

test('a choice naming a product off this server, a closed client or a past due date is refused', function () {
    $this->kit->priceBook->addProduct(30, 'Elsewhere', driver: 'cpanel', serverGroupId: 999, driverConfig: ['package' => 'root_basic']);
    $closed = Client::factory()->create(['status' => 'closed']);
    $url = route('admin.services.servers.import.select', $this->server);

    $this->put($url, ['usernames' => ['bravo'], 'product_id' => 30])->assertSessionHasErrors('product_id');
    $this->put($url, ['usernames' => ['bravo'], 'client_id' => $closed->id])->assertSessionHasErrors('client_id');
    $this->put($url, ['usernames' => ['bravo'], 'next_due_date' => '2026-10-01'])->assertSessionHasErrors('next_due_date');
    $this->put($url, ['usernames' => ['not-on-server'], 'include' => true])->assertSessionHasNoErrors();

    expect(ServerAccountImport::query()->sole()->selections)->toBeEmpty();
});

test('importing needs a recent password and the sensitive import permission', function () {
    $this->withSession(['auth.password_confirmed_at' => 0]);
    $this->post(route('admin.services.servers.import.apply', $this->server))->assertSessionHasErrors('password_confirmation');

    actingAsStaff(['services.*']);
    confirmPassword();
    $this->post(route('admin.services.servers.import.apply', $this->server))->assertForbidden();
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true])->assertForbidden();
    $this->getJson(route('admin.services.servers.import.clients', $this->server))->assertForbidden();
    $this->get(route('admin.services.servers.import.review', $this->server))->assertForbidden();

    expect(Service::query()->count())->toBe(0);
});
