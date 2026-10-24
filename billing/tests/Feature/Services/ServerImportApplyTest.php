<?php

use App\Events\Services\ServiceImported;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Jobs\ImportReviewedAccounts;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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

test('a confirmed account becomes a service as the server holds it, with no invoice, mail or module command, once', function () {
    Event::fake([ServiceImported::class]);
    Mail::fake();
    // alpha is suspended on the server in this list.
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [[...Whm::fixture('listaccts_page1')['data']['acct'][0], 'suspended' => 1, 'suspendreason' => 'Abuse report']]]])]);
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    chooseFor($this->server, ['proposed' => true]);

    $this->get(route('admin.services.servers.import.review', $this->server))->assertInertia(fn (Assert $page) => $page
        ->component('admin/services/servers/import-review')
        ->has('rows', 1)
        ->where('totals', [['currency' => 'USD', 'cycle' => 'monthly', 'amount' => 1200, 'accounts' => 1]]));
    applyReviewed($this->server);
    $digest = $this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('digest');
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasErrors('import');

    $import = ServerAccountImport::query()->sole();
    $alpha = Service::query()->sole();
    expect($import->only(['status', 'linked_count', 'proposed_count', 'selections']))->toBe(['status' => 'ready', 'linked_count' => 1, 'proposed_count' => 0, 'selections' => null])
        ->and($alpha)
        ->username->toBe('alpha')
        ->client_id->toBe($this->anna->id)
        ->recurring_amount->toBe(1200)
        ->domain->toBe('alpha-books.com')
        ->password->toBeNull()
        ->status->toBe(ServiceStatus::Suspended)
        ->suspension_reason->toBe('staff:Imported (suspended on the server: Abuse report)')
        ->registration_date->toDateString()->toBe('2023-11-14')
        ->next_due_date->toDateString()->toBe('2026-11-14')
        ->driver_data->toMatchArray(['owner' => 'root', 'plan' => 'root_basic'])
        ->and($this->kit->invoicer->invoices)->toBe([])
        ->and(ModuleCommand::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('subject_type', $alpha->getMorphClass())->where('subject_id', $alpha->id)->sole()->properties)->toMatchArray(['server' => 'Shared 1', 'username' => 'alpha', 'product' => 'Basic hosting'])
        ->and(ActivityLog::query()->where('description', 'Imported 1 accounts from server Shared 1')->exists())->toBeTrue();
    Mail::assertNothingSent();
    Event::assertDispatchedTimes(ServiceImported::class, 1);
    Event::assertDispatched(ServiceImported::class, fn (ServiceImported $event): bool => $event->serviceId === $alpha->id && $event->source === 'server_import');
});

/**
 * A ready list of $count proposed accounts for one client, all ticked; returns the import after review and confirm.
 *
 * @param  list<string>|null  $usernames
 */
function confirmedImport(Server $server, int $clientId, int $count, ?array $usernames = null): ServerAccountImport
{
    $usernames ??= array_map(fn (int $i): string => "acct{$i}", range(1, $count));
    $accounts = array_map(fn (string $username): array => ['username' => $username, 'domain' => "{$username}.test", 'plan' => 'root_basic', 'owner' => 'root', 'email' => 'anna@alpha-books.com', 'ip' => null,
        'suspended' => false, 'suspend_reason' => null, 'created_at' => '2025-03-15T00:00:00+00:00', 'disk_used_mb' => 1, 'disk_limit_mb' => 10,
        'category' => 'proposed', 'client_id' => $clientId, 'product_id' => 21, 'service_id' => null, 'note' => null, 'differences' => []], $usernames);
    ServerAccountImport::query()->where('server_id', $server->id)->delete();
    importWithAccounts($accounts, ['server_id' => $server->id, 'status' => 'ready', 'account_count' => count($accounts), 'missing' => [],
        'connection_user' => 'root', 'selections' => array_fill_keys($usernames, ['include' => true]), 'fetched_at' => now(), 'expires_at' => now()->addDay()]);
    Queue::fake([ImportReviewedAccounts::class]);
    applyReviewed($server);

    return ServerAccountImport::query()->where('server_id', $server->id)->sole();
}

test('a chunk that fails stops the import with the reason, and every reviewed account is reported', function () {
    $import = confirmedImport($this->server, $this->anna->id, 300);
    (new ImportReviewedAccounts($import->id, auth()->id(), 0, $import->reviewed['digest']))->handle(app(ImportServerAccounts::class));
    (new ImportReviewedAccounts($import->id, auth()->id(), 100, $import->reviewed['digest']))->failed(new RuntimeException('worker died'));

    $import->refresh();
    expect($import->status)->toBe('ready')
        ->and($import->stop_reason)->toBe('The background job failed.')
        ->and([$import->imported_count, $import->not_imported_count])->toBe([100, 200])
        ->and(collect($import->report)->pluck('username')->unique()->count())->toBe(300)
        ->and(Service::query()->count())->toBe(100);
});

test('a username longer than 32 characters is reported on every database', function () {
    $long = str_repeat('a', 40);
    $import = confirmedImport($this->server, $this->anna->id, 1, [$long]);

    app(ImportServerAccounts::class)->stop($this->server, $import, null, 'test');

    expect($import->refresh()->report[0]['username'])->toBe($long);
});
