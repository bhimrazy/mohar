<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\FetchAccountsForImport;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Jobs\ImportReviewedAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\AccountImportPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Security re-check of #95 (import step 3b-1), round 4: the queued job creates the reviewed rows. R4-1 to R4-4
 * reproduce what still reaches the job without a review; R4-5 to R4-7 and R4-9 pin defences that hold; R4-8 is a Low.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200], driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_basic']);

    // Three root-owned accounts, each matching one client by contact email (all proposed).
    $alpha = Whm::fixture('listaccts_page1')['data']['acct'][0];
    $accounts = [];
    $this->clients = [];
    foreach (['acme', 'beta', 'gamma'] as $i => $user) {
        $accounts[] = [...$alpha, 'user' => $user, 'domain' => "{$user}.example", 'email' => "owner@{$user}.example", 'unix_startdate' => 1700000000 + $i];
        $this->clients[$user] = Client::factory()->create(['email' => "owner@{$user}.example", 'currency' => 'USD']);
    }
    $this->mallory = Client::factory()->create(['email' => 'mallory@example.net', 'currency' => 'USD']);
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => $accounts]])]);
    $this->staff = actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    Queue::fake([ImportReviewedAccounts::class]);
});

function r4Tick(Server $server, array $usernames, bool $include = true): void
{
    test()->put(route('admin.services.servers.import.select', $server), ['usernames' => $usernames, 'include' => $include])->assertSessionHasNoErrors();
}

function r4Digest(Server $server): string
{
    return test()->get(route('admin.services.servers.import.review', $server))->assertOk()->inertiaProps('digest');
}

function r4RunQueued(): void
{
    Queue::pushed(ImportReviewedAccounts::class)->each(fn (ImportReviewedAccounts $job) => $job->handle(app(ImportServerAccounts::class)));
}

test('[R4-1] a review GET that passed ready() before the confirm committed does not change what the queued import creates', function () {
    r4Tick($this->server, ['acme']);
    $digest = r4Digest($this->server);
    // A second review (another tab or staff member) read the list while it was ready...
    $stale = FetchAccountsForImport::latest($this->server);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasNoErrors();

    // ...and saves its rows after start() set the import running: here Mallory now holds the contact email.
    $this->clients['acme']->update(['email' => 'owner@moved.example']);
    $this->mallory->update(['email' => 'owner@acme.example']);
    app(AccountImportPlan::class)->review($this->server, $stale, $this->staff->id);
    r4RunQueued();

    expect(Service::query()->where('username', 'acme')->value('client_id'))->toBe($this->clients['acme']->id);
});

test('[R4-2] a live service holding the username in another case, created after the confirm, keeps the account out', function () {
    r4Tick($this->server, ['acme']);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r4Digest($this->server)])->assertSessionHasNoErrors();

    Service::factory()->create(['server_id' => $this->server->id, 'username' => 'Acme', 'status' => ServiceStatus::Active]);
    r4RunQueued();

    expect(Service::query()->where('server_id', $this->server->id)->whereRaw('lower(username) = ?', ['acme'])->count())->toBe(1);
});

test('[R4-3] a client whose currency changed after the confirm is not billed the reviewed amount in the new currency', function () {
    r4Tick($this->server, ['acme']);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r4Digest($this->server)])->assertSessionHasNoErrors();

    $this->clients['acme']->update(['currency' => 'EUR']);
    r4RunQueued();

    $service = Service::query()->where('username', 'acme')->first();
    expect($service === null || $service->currency === 'USD')->toBeTrue();
});

test('[R4-4] a chunk that fails halfway reports the accounts it imported, and a retry imports only the rest', function () {
    r4Tick($this->server, ['acme', 'beta', 'gamma']);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r4Digest($this->server)])->assertSessionHasNoErrors();

    Service::creating(fn (Service $service) => $service->username === 'beta' ? throw new RuntimeException('worker died') : null);
    $job = Queue::pushed(ImportReviewedAccounts::class)->first();
    try {
        $job->handle(app(ImportServerAccounts::class));
    } catch (RuntimeException $e) {
        $job->failed($e);
    }

    $import = ServerAccountImport::query()->sole();
    expect(Service::query()->pluck('username')->all())->toBe(['acme'])
        ->and($import->status)->toBe(ServerAccountImport::READY)
        ->and(collect($import->report)->where('imported', true)->pluck('username')->all())->toBe(['acme']);
});

test('[R4-5] after a failed chunk, ticking everything again imports only the missing accounts, never a second service', function () {
    r4Tick($this->server, ['acme', 'beta', 'gamma']);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r4Digest($this->server)])->assertSessionHasNoErrors();
    Service::creating(fn (Service $service) => $service->username === 'beta' ? throw new RuntimeException('worker died') : null);
    $job = Queue::pushed(ImportReviewedAccounts::class)->first();
    try {
        $job->handle(app(ImportServerAccounts::class));
    } catch (RuntimeException $e) {
        $job->failed($e);
    }
    Service::flushEventListeners();
    Queue::fake([ImportReviewedAccounts::class]);

    $this->put(route('admin.services.servers.import.select', $this->server), ['proposed' => true])->assertSessionHasNoErrors();
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r4Digest($this->server)])->assertSessionHasNoErrors();
    r4RunQueued();

    expect(Service::query()->orderBy('username')->pluck('username')->all())->toBe(['acme', 'beta', 'gamma']);
});

test('[R4-6] unticking a reviewed account before confirming refuses the stale digest; ticking waits while importing', function () {
    r4Tick($this->server, ['acme', 'beta']);
    $digest = r4Digest($this->server);
    r4Tick($this->server, ['beta'], include: false);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasErrors('import');

    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r4Digest($this->server)])->assertSessionHasNoErrors();
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['gamma'], 'include' => true])->assertSessionHasErrors('import');
    r4RunQueued();

    expect(Service::query()->pluck('username')->all())->toBe(['acme']);
});

test('[R4-7] a server deleted before its first chunk ran leaves the job with nothing to do', function () {
    r4Tick($this->server, ['acme']);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r4Digest($this->server)])->assertSessionHasNoErrors();

    $this->delete(route('admin.services.servers.destroy', $this->server))->assertSessionHasNoErrors();
    r4RunQueued();

    expect(Service::query()->count())->toBe(0)->and(ServerAccountImport::query()->count())->toBe(0);
});

test('[R4-8] a confirmed import stops when the staff member who confirmed it is deactivated', function () {
    r4Tick($this->server, ['acme']);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r4Digest($this->server)])->assertSessionHasNoErrors();

    $this->staff->forceFill(['is_active' => false])->save();
    r4RunQueued();

    expect(Service::query()->count())->toBe(0);
});

test('[R4-9] the "requested" audit entry records the digest, a hash of the reviewed usernames, the count and the reviewer', function () {
    r4Tick($this->server, ['acme', 'beta']);
    $digest = r4Digest($this->server);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasNoErrors();

    $entry = ActivityLog::query()->where('description', 'like', 'Import of 2 accounts%requested')->sole();
    expect($entry->properties)->toMatchArray(['accounts' => 2, 'digest' => $digest, 'usernames' => hash('sha256', 'acme,beta'), 'reviewed_by' => $this->staff->id])
        ->and(json_encode($entry->properties))->not->toContain('owner@acme.example');
});
