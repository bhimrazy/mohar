<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\FetchAccountsForImport;
use App\Modules\Services\Actions\ImportServerAccounts;
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
 * Security re-check of #95 (import step 3b-1), round 5: a chunk that stops (the confirmer lost the permission, or the
 * reviewed rows changed) leaves a report staff can act on, and the rest can be imported again without duplicates.
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

function r5Tick(Server $server, array $usernames): void
{
    test()->put(route('admin.services.servers.import.select', $server), ['usernames' => $usernames, 'include' => true])->assertSessionHasNoErrors();
}

function r5Digest(Server $server): string
{
    return test()->get(route('admin.services.servers.import.review', $server))->assertOk()->inertiaProps('digest');
}

/**
 * Confirm acme, beta and gamma, import acme as the first chunk would, then stop the next chunk: the confirmer is deactivated.
 */
function r5StopAfterFirst(Server $server, $staff): string
{
    r5Tick($server, ['acme', 'beta', 'gamma']);
    $digest = r5Digest($server);
    test()->post(route('admin.services.servers.import.apply', $server), ['digest' => $digest])->assertSessionHasNoErrors();
    $import = ServerAccountImport::query()->sole();
    app(ImportServerAccounts::class)->handle($server, $import, $staff, ['acme']);
    $staff->forceFill(['is_active' => false])->save();
    (new ImportReviewedAccounts($import->id, $staff->id, 1, $digest))->handle(app(ImportServerAccounts::class));

    return $digest;
}

test('[R5-1] a stopped import keeps what it created, and importing again creates only the rest', function () {
    r5StopAfterFirst($this->server, $this->staff);
    expect(Service::query()->pluck('username')->all())->toBe(['acme'])
        ->and(ServerAccountImport::query()->sole()->status)->toBe(ServerAccountImport::READY);

    $this->staff->forceFill(['is_active' => true])->save();
    Queue::fake([ImportReviewedAccounts::class]);
    $this->put(route('admin.services.servers.import.select', $this->server), ['proposed' => true])->assertSessionHasNoErrors();
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r5Digest($this->server)])->assertSessionHasNoErrors();
    Queue::pushed(ImportReviewedAccounts::class)->each(fn (ImportReviewedAccounts $job) => $job->handle(app(ImportServerAccounts::class)));

    expect(Service::query()->orderBy('username')->pluck('username')->all())->toBe(['acme', 'beta', 'gamma']);
});

test('[R5-2] a stopped import\'s report says why it stopped and names each reviewed account it did not reach', function () {
    r5StopAfterFirst($this->server, $this->staff);

    $report = collect(ServerAccountImport::query()->sole()->report);
    // beta and gamma were reviewed and confirmed but never reached: each should be listed with the reason.
    expect($report->pluck('username')->filter()->values()->all())->toBe(['acme', 'beta', 'gamma'])
        // Since the #133 product review the reason is stored once, on the import; unreached lines read "Not reached."
        ->and(ServerAccountImport::query()->sole()->stop_reason)->toContain('no longer import')
        ->and($report->firstWhere('username', 'beta')['message'] ?? '')->toBe('Not reached.');
});

test('[R5-3] a review saved while an import runs does not replace the confirmed rows, and its digest starts nothing', function () {
    r5Tick($this->server, ['acme']);
    $digest = r5Digest($this->server);
    $stale = FetchAccountsForImport::latest($this->server);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasNoErrors();

    $other = app(AccountImportPlan::class)->review($this->server, $stale, $this->staff->id)['digest'];
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1500], driver: 'cpanel', serverGroupId: ServerGroup::query()->value('id'), driverConfig: ['package' => 'root_basic']);
    $changed = app(AccountImportPlan::class)->review($this->server, $stale, $this->staff->id)['digest'];

    expect(ServerAccountImport::query()->sole()->reviewed['digest'])->toBe($digest)->and($changed)->not->toBe($digest);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $changed])->assertSessionHasErrors('import');
    Queue::assertPushed(ImportReviewedAccounts::class, 1);
});
