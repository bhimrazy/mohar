<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Jobs\FetchServerAccounts;
use App\Modules\Services\Jobs\ImportReviewedAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\AccountImportMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Review board probes for PR #133 (import accounts, step 3b-2). Each test fails at 0fe00402 and passes once the
 * finding is fixed.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    ServerGroup::factory()->create()->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200, 'annually' => 12000], driver: 'cpanel', serverGroupId: ServerGroup::query()->value('id'), driverConfig: ['package' => 'root_basic']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    $this->bea = Client::factory()->create(['email' => 'bea@example.com', 'currency' => 'USD']);
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
    actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
});

test('RB133-1: clearing a chosen client in the dialog returns the account to its match (PR: "Clearing a field returns that account to its default")', function () {
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true, 'client_id' => $this->bea->id])->assertSessionHasNoErrors();

    // What the dialog would have to send to clear the picker; today it omits client_id, which keeps the old choice.
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true, 'client_id' => null, 'product_id' => null])
        ->assertSessionHasNoErrors();

    expect(ServerAccountImport::query()->sole()->selections['alpha'])->not->toHaveKey('client_id');
});

test('RB133-2: a fetch that was superseded while it ran does not delete the last good list when it finishes', function () {
    Queue::fake([FetchServerAccounts::class]);
    $good = ServerAccountImport::query()->sole();
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true])->assertSessionHasNoErrors();

    // A fetch whose job has started reading the server, then goes stale (> STALE_MINUTES) while it reads.
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    $stale = ServerAccountImport::query()->latest('id')->firstOrFail();
    $accounts = array_map(fn (array $row): array => array_diff_key($row, array_flip(['category', 'client_id', 'product_id', 'service_id', 'note', 'differences'])), $good->refresh()->accountRows());
    ServerAccountImport::query()->whereKey($stale->id)->toBase()->update(['updated_at' => now()->subMinutes(ServerAccountImport::STALE_MINUTES + 1)]);

    // Staff fetch again: the stale row is deleted and a new fetch is queued.
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();

    // The first job now finishes and stores its (deleted) row.
    app(AccountImportMatcher::class)->store($stale, $accounts, 'root');

    expect(ServerAccountImport::query()->whereKey($good->id)->exists())->toBeTrue('the last good list was deleted by a superseded fetch')
        ->and(ServerAccountImport::query()->whereKey($stale->id)->exists())->toBeFalse();
});

test('RB133-3: finish() does not re-link an account from an earlier run whose service has ended since', function () {
    Queue::fake([ImportReviewedAccounts::class]);
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha', 'bravo'], 'include' => true, 'client_id' => $this->anna->id, 'product_id' => 21])->assertSessionHasNoErrors();
    $digest = $this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('digest');
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasNoErrors();
    $import = ServerAccountImport::query()->sole();
    app(ImportServerAccounts::class)->handle($this->server, $import, auth()->user(), ['alpha']);
    (new ImportReviewedAccounts($import->id, auth()->id(), 1, $digest))->failed(new RuntimeException('worker died'));

    // alpha's service ends; Fetch again matches alpha afresh (no live service), and the stopped report moves along.
    Service::query()->where('username', 'alpha')->update(['status' => 'terminated']);
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    expect(collect(ServerAccountImport::query()->sole()->accountRows())->keyBy('username')['alpha']['category'])->not->toBe(AccountImportMatcher::LINKED);

    // Going on with bravo only.
    $digest = $this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('digest');
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasNoErrors();
    $import = ServerAccountImport::query()->sole();
    app(ImportServerAccounts::class)->handle($this->server, $import, auth()->user());
    app(ImportServerAccounts::class)->finish($this->server, $import->refresh(), auth()->user());

    $alpha = collect(ServerAccountImport::query()->sole()->accountRows())->keyBy('username')['alpha'];
    expect($alpha['category'])->not->toBe(AccountImportMatcher::LINKED, 'alpha was marked linked to its terminated service from the earlier run\'s report line');
});
