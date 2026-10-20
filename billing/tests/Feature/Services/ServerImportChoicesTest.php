<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Jobs\ImportReviewedAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Import accounts, step 3b-2: choosing per account and in bulk, the client picker, choices kept across "Fetch again",
 * and proposed matches whose client changed email recently.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    ServerGroup::factory()->create()->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200, 'annually' => 12000], driver: 'cpanel', serverGroupId: ServerGroup::query()->value('id'), driverConfig: ['package' => 'root_basic']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
    actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
});

test('a changed email is recorded on the client and on the login', function () {
    $user = User::factory()->create();

    $this->anna->update(['email' => 'anna@new.example']);
    $user->update(['email' => 'login@new.example']);

    expect($this->anna->refresh()->email_changed_at?->toDateTimeString())->toBe('2026-10-19 10:00:00')
        ->and($user->refresh()->email_changed_at?->toDateTimeString())->toBe('2026-10-19 10:00:00');
});

test('Select all proposed leaves out a match whose client changed email in the last 30 days, and the row says so', function () {
    $this->anna->forceFill(['email_changed_at' => now()->subDays(3)])->save();

    $this->put(route('admin.services.servers.import.select', $this->server), ['proposed' => true])
        ->assertSessionHas('inertia.flash_data.toast.message', '1 proposed account was not ticked: its client changed email recently or has not verified it. Check it before ticking.');

    $alpha = collect($this->get(route('admin.services.servers.import.show', $this->server))->inertiaProps('rows.data'))->keyBy('username')['alpha'];
    expect(ServerAccountImport::query()->sole()->selections)->toBeEmpty()
        ->and($alpha['flag']['kind'])->toBe('email_changed')
        ->and($alpha['flag']['at'])->toStartWith('2026-10-16');
});

test('a client who signed up and has not verified the email is shown but not proposed, and is imported only when chosen', function () {
    $this->anna->delete();
    $signup = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    $signup->users()->attach(User::factory()->unverified()->create(['email' => 'anna@alpha-books.com']), ['is_owner' => true]);
    $verified = Client::factory()->create(['email' => 'chef@bravo-cafe.com', 'currency' => 'USD']);
    $verified->users()->attach(User::factory()->create(['email' => 'chef@bravo-cafe.com']), ['is_owner' => true]);
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();

    $rows = collect($this->get(route('admin.services.servers.import.show', $this->server))->inertiaProps('rows.data'))->keyBy('username');
    expect($rows['alpha']['category'])->toBe('unmatched')
        ->and($rows['alpha']['client']['id'])->toBe($signup->id)
        ->and($rows['alpha']['flag']['kind'])->toBe('unverified');

    // Ticked without choosing the client: the review keeps it out; chosen explicitly, it can be imported.
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true, 'product_id' => 21])->assertSessionHasNoErrors();
    $alpha = collect($this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('rows'))->keyBy('username')['alpha'];
    expect($alpha['decision']['problems'])->toBe(['This client has not verified their email: choose the client yourself to import it.'])
        ->and($alpha['decision']['flag']['kind'])->toBe('unverified');

    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'client_id' => $signup->id])->assertSessionHasNoErrors();
    $alpha = collect($this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('rows'))->keyBy('username')['alpha'];
    expect($alpha['decision']['problems'])->toBe([])->and($alpha['decision']['flag']['kind'])->toBe('unverified');
});

test('a choice is not carried by Fetch again to an account re-created under the same name', function () {
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha', 'charlie'], 'include' => true])->assertSessionHasNoErrors();
    $accounts = array_map(fn (array $account): array => $account['user'] === 'alpha' ? [...$account, 'unix_startdate' => 1790000000] : $account,
        [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]);
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => $accounts]])]);

    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();

    expect(ServerAccountImport::query()->sole()->selections)->toBe(['charlie' => ['include' => true]]);
});

test('the cycle and due date can be set for every ticked account at once, and choices survive Fetch again', function () {
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha', 'charlie'], 'include' => true])->assertSessionHasNoErrors();
    $this->put(route('admin.services.servers.import.select', $this->server), ['ticked' => true, 'cycle' => 'annually', 'next_due_date' => '2027-02-01'])->assertSessionHasNoErrors();

    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => Whm::fixture('listaccts_page1')['data']['acct']]])]);
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();

    expect(ServerAccountImport::query()->sole()->selections)->toBe(['alpha' => ['include' => true, 'cycle' => 'annually', 'next_due_date' => '2027-02-01']]);
});

test('the client picker lists active clients, the most recently added first before anything is typed', function () {
    Client::factory()->create(['status' => 'closed']);
    $newest = Client::factory()->create(['first_name' => 'Zed']);

    $listed = $this->getJson(route('admin.services.servers.import.clients', $this->server))->assertOk()->json();

    expect($listed[0]['id'])->toBe($newest->id)
        ->and(collect($listed)->pluck('id')->all())->toBe([$newest->id, $this->anna->id])
        ->and($this->getJson(route('admin.services.servers.import.clients', [$this->server, 'q' => 'alpha-books']))->json('0.id'))->toBe($this->anna->id);
});

test('choosing a client needs the permission to view clients, and choices are checked', function () {
    $this->kit->priceBook->addProduct(30, 'Elsewhere', driver: 'cpanel', serverGroupId: 999, driverConfig: ['package' => 'root_basic']);
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['bravo'], 'product_id' => 30, 'next_due_date' => '2026-10-01'])
        ->assertSessionHasErrors(['product_id', 'next_due_date']);

    actingAsStaff(['services.servers.import']);
    confirmPassword();
    $this->getJson(route('admin.services.servers.import.clients', $this->server))->assertForbidden();
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['bravo'], 'client_id' => $this->anna->id])->assertSessionHasErrors('client_id');
});

test('a stopped import keeps the choices it did not reach, and its report survives Fetch again', function () {
    Queue::fake([ImportReviewedAccounts::class]);
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha', 'bravo'], 'include' => true, 'client_id' => $this->anna->id, 'product_id' => 21])->assertSessionHasNoErrors();
    $digest = $this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('digest');
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasNoErrors();
    $import = ServerAccountImport::query()->sole();
    app(ImportServerAccounts::class)->handle($this->server, $import, auth()->user(), ['alpha']);
    (new ImportReviewedAccounts($import->id, auth()->id(), 1, $digest))->failed(new RuntimeException('worker died'));

    expect(ServerAccountImport::query()->sole()->selections)->toBe(['bravo' => ['include' => true, 'client_id' => $this->anna->id, 'product_id' => 21]]);

    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    $this->get(route('admin.services.servers.import.show', $this->server))->assertInertia(fn (Assert $page) => $page
        ->where('included', 1)
        ->where('import.report.stop_reason', 'The background job failed.')
        ->where('import.report.not_reached', 1)
        ->where('import.report.lines.0.username', 'bravo')
        ->where('import.report.lines.0.message', 'Not reached.'));

    // Going on: one report for both runs, with this run's progress counted on its own.
    $digest = $this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('digest');
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasNoErrors();
    $this->get(route('admin.services.servers.import.show', $this->server))->assertInertia(fn (Assert $page) => $page->where('import.progress', ['done' => 0, 'total' => 1]));
    $import = ServerAccountImport::query()->sole();
    app(ImportServerAccounts::class)->handle($this->server, $import, auth()->user());
    app(ImportServerAccounts::class)->finish($this->server, $import->refresh(), auth()->user());

    $this->get(route('admin.services.servers.import.show', $this->server))->assertInertia(fn (Assert $page) => $page
        ->where('import.report.imported', 2)
        ->where('import.report.not_imported', 0)
        ->where('import.report.stop_reason', null));
});

test('a failed fetch leaves the last list in place and is shown beside it', function () {
    Whm::fake(['listaccts' => Http::failedConnection('cURL error 7: refused')]);
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();

    $this->get(route('admin.services.servers.import.show', $this->server))->assertInertia(fn (Assert $page) => $page
        ->where('import.status', 'ready')
        ->where('import.failure.error', fn (string $error): bool => str_starts_with($error, 'The accounts on Shared 1 could not be read'))
        ->has('rows.data', 3));
});

test('the bulk cycle says how many ticked accounts it leaves out, and the default undoes it', function () {
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true])->assertSessionHasNoErrors();

    $this->put(route('admin.services.servers.import.select', $this->server), ['ticked' => true, 'cycle' => 'quarterly'])
        ->assertSessionHas('inertia.flash_data.toast.message', '1 ticked account has no quarterly price for its product and is left out until you change its cycle.');
    $this->put(route('admin.services.servers.import.select', $this->server), ['ticked' => true, 'cycle' => null])->assertSessionHasNoErrors();

    expect(ServerAccountImport::query()->sole()->selections)->toBe(['alpha' => ['include' => true]]);
});
