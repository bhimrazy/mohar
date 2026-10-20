<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Jobs\ImportReviewedAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Security review of #133 (Import accounts 3b-2: choices, client picker, continue a stopped import, the recent
 * email change rule). Probes tagged [S133-n]; the ones named in the review as findings fail at 1d51281e.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $this->group = ServerGroup::factory()->create();
    $this->group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200, 'annually' => 12000], driver: 'cpanel', serverGroupId: $this->group->id, driverConfig: ['package' => 'root_basic']);
    // alpha: owner root, contact anna@alpha-books.com, package root_basic; bravo: owned by reseller1; charlie: no email.
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
});

function s133Fetch(Server $server): void
{
    test()->post(route('admin.services.servers.import.fetch', $server))->assertSessionHasNoErrors();
}

function s133Select(Server $server, array $choice): void
{
    test()->put(route('admin.services.servers.import.select', $server), $choice)->assertSessionHasNoErrors();
}

/**
 * @return array<string, array<string, mixed>>
 */
function s133Review(Server $server): array
{
    return collect(test()->get(route('admin.services.servers.import.review', $server))->assertOk()->inertiaProps('rows'))->keyBy('username')->all();
}

/**
 * @return array{0: User, 1: Client}
 */
function s133ClientLogin(): array
{
    $client = Client::factory()->create();
    $user = User::factory()->create(['email' => $client->email]);
    $client->users()->attach($user, ['is_owner' => true]);
    test()->actingAs($user);

    return [$user, $client];
}

function s133Staff(array $permissions = ['*']): User
{
    $staff = actingAsStaff($permissions);
    confirmPassword();

    return $staff;
}

// The recent email change rule

test('[S133-1] a visitor who registers with an account\'s contact email is not ticked by "Select all proposed"', function () {
    // Anonymous sign-up with the contact address of a hosting customer who is not a client yet (the usual migration
    // case). The client is active at once; the address is never verified.
    $this->post(route('register'), ['name' => 'Anna Alpha', 'email' => 'anna@alpha-books.com', 'password' => 'Correct-Horse-9-Battery', 'password_confirmation' => 'Correct-Horse-9-Battery']);
    $attacker = Client::query()->where('email', 'anna@alpha-books.com')->sole();
    // Unverified, the login can still move itself to an address the visitor controls (settings/profile needs no
    // verified email); the client's email stays the victim's.
    $this->patch(route('profile.update'), ['name' => 'Anna Alpha', 'email' => 'visitor@attacker.example'])->assertSessionHasNoErrors();
    expect($attacker->refresh()->email)->toBe('anna@alpha-books.com');

    s133Staff();
    s133Fetch($this->server);
    $this->put(route('admin.services.servers.import.select', $this->server), ['proposed' => true]);

    expect(ServerAccountImport::query()->sole()->selections['alpha']['include'] ?? false)->toBeFalse();
});

test('[S133-2] a proposed account ticked in bulk, then re-matched to a client whose email just changed, is flagged on the review', function () {
    $anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    $mallory = Client::factory()->create(['email' => 'mallory@example.net', 'currency' => 'USD']);
    s133Staff();
    s133Fetch($this->server);
    s133Select($this->server, ['proposed' => true]);

    // After the bulk tick, Anna moves to a new address and Mallory takes the old one (client area, no verification).
    $anna->update(['email' => 'anna@new.example']);
    $mallory->update(['email' => 'anna@alpha-books.com']);
    expect($mallory->refresh()->email_changed_at)->not->toBeNull();

    $alpha = s133Review($this->server)['alpha'];

    // The review re-matches today: it must not present Mallory as a ready match without the 30-day flag.
    expect($alpha['decision']['client']['id'] ?? null)->toBe($mallory->id)
        ->and($alpha['decision']['problems'] !== [] || ! empty($alpha['decision']['email_changed_at'] ?? null))->toBeTrue();
});

test('[S133-3] every model path that changes a client email records it; the column cannot be written through the forms', function () {
    $client = Client::factory()->create(['email' => 'a@example.com']);
    $client->forceFill(['email' => 'b@example.com'])->save();
    expect($client->refresh()->email_changed_at)->not->toBeNull();

    $client->forceFill(['email_changed_at' => null])->save();
    $client->update(['email' => 'c@example.com']);
    expect($client->refresh()->email_changed_at)->not->toBeNull();

    // Admin form: email_changed_at is neither validated nor fillable.
    $client->forceFill(['email_changed_at' => now()->subDays(2)])->save();
    s133Staff();
    $this->put(route('admin.clients.update', $client), [
        'first_name' => 'A', 'last_name' => 'B', 'email' => 'c@example.com', 'currency' => 'USD', 'status' => 'active', 'email_changed_at' => '2020-01-01 00:00:00',
    ])->assertSessionHasNoErrors();
    expect($client->refresh()->email_changed_at->toDateString())->toBe(now()->subDays(2)->toDateString());

    // Client area form changes the email: recorded.
    [$user, $own] = s133ClientLogin();
    $own->forceFill(['email_changed_at' => null])->save();
    $this->put(route('client.account.update'), [
        'first_name' => 'M', 'last_name' => 'X', 'email' => 'anna@alpha-books.com', 'address_1' => '1 St', 'city' => 'C', 'postcode' => '1', 'country' => 'US', 'email_changed_at' => '2020-01-01',
    ])->assertSessionHasNoErrors();
    expect($own->refresh()->email_changed_at?->toDateTimeString())->toBe('2026-10-19 10:00:00');
});

// The client picker

test('[S133-4] the client picker needs both permissions and is closed to guests and client logins', function () {
    $url = route('admin.services.servers.import.clients', $this->server);
    $this->getJson($url)->assertUnauthorized();

    s133ClientLogin();
    expect($this->getJson($url)->status())->toBeIn([302, 403]);

    s133Staff(['core.clients.view']);
    $this->getJson($url)->assertForbidden();
    s133Staff(['services.servers.import']);
    $this->getJson($url)->assertForbidden();
    s133Staff(['services.servers.import', 'core.clients.view']);
    $this->getJson($url)->assertOk();
});

test('[S133-5] the client picker lists only active clients, at most 20, with only the fields it needs', function () {
    $closed = Client::factory()->create(['status' => 'closed', 'first_name' => 'Hidden', 'email' => 'hidden-closed@example.com']);
    $inactive = Client::factory()->create(['status' => 'inactive', 'first_name' => 'Hidden', 'email' => 'hidden-inactive@example.com']);
    Client::factory()->count(25)->create(['first_name' => 'Hidden']);
    s133Staff();

    $listed = $this->getJson(route('admin.services.servers.import.clients', [$this->server, 'q' => 'Hidden']))->assertOk()->json();
    $searchedClosed = $this->getJson(route('admin.services.servers.import.clients', [$this->server, 'q' => 'hidden-closed']))->json();

    expect($listed)->toHaveCount(20)
        ->and(collect($listed)->pluck('id'))->not->toContain($closed->id)->not->toContain($inactive->id)
        ->and($searchedClosed)->toBe([])
        ->and(array_keys($listed[0]))->toEqualCanonicalizing(['id', 'display_name', 'email', 'currency']);
});

test('[S133-6] the client picker is throttled per staff member', function () {
    s133Staff();
    $url = route('admin.services.servers.import.clients', $this->server);

    foreach (range(1, 60) as $ignored) {
        $this->getJson($url)->assertOk();
    }

    $this->getJson($url)->assertTooManyRequests();
});

// Choices

test('[S133-7] crafted choices are refused: another server\'s product, a non-recurring cycle, past, absurd or invalid dates, a non-active client', function (array $choice, string $field) {
    $this->kit->priceBook->addProduct(30, 'Elsewhere', ['monthly' => 100], driver: 'cpanel', serverGroupId: 999, driverConfig: ['package' => 'root_basic']);
    Client::factory()->create(['id' => 9001, 'status' => 'inactive']);
    s133Staff();
    s133Fetch($this->server);

    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true, ...$choice])->assertSessionHasErrors($field);
    expect(ServerAccountImport::query()->sole()->selections)->toBeEmpty();
})->with([
    'product elsewhere' => [['product_id' => 30], 'product_id'],
    'product unknown' => [['product_id' => 424242], 'product_id'],
    'free cycle' => [['cycle' => 'free'], 'cycle'],
    'one-time cycle' => [['cycle' => 'onetime'], 'cycle'],
    'yesterday' => [['next_due_date' => '2026-10-18'], 'next_due_date'],
    'far future' => [['next_due_date' => '2099-01-01'], 'next_due_date'],
    'impossible date' => [['next_due_date' => '2027-02-30'], 'next_due_date'],
    'datetime' => [['next_due_date' => '2027-01-01 00:00:00'], 'next_due_date'],
    'inactive client' => [['client_id' => 9001], 'client_id'],
    'client as array' => [['client_id' => [1]], 'client_id'],
]);

test('[S133-8] only the choice fields are stored, whatever the scope', function () {
    $anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    s133Staff();
    s133Fetch($this->server);
    $extra = ['amount' => 1, 'recurring_amount' => 0, 'currency' => 'EUR', 'anchor_day' => 31, 'service_id' => 1, 'username' => 'root', 'reviewed' => ['digest' => str_repeat('a', 64)]];

    s133Select($this->server, ['usernames' => ['alpha'], 'include' => true, 'product_id' => 21, ...$extra]);
    s133Select($this->server, ['ticked' => true, 'cycle' => 'annually', ...$extra]);
    s133Select($this->server, ['proposed' => true, 'cycle' => 'monthly', 'client_id' => $anna->id, ...$extra]);

    $import = ServerAccountImport::query()->sole();
    expect($import->selections)->toBe(['alpha' => ['include' => true, 'product_id' => 21, 'cycle' => 'annually']])
        ->and($import->reviewed)->toBeNull();
});

test('[S133-9] the bulk "ticked" scope sets only the cycle and due date, never one client for every ticked account', function () {
    Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    $mallory = Client::factory()->create(['email' => 'mallory@example.net', 'currency' => 'USD']);
    s133Staff();
    s133Fetch($this->server);
    s133Select($this->server, ['proposed' => true]);

    $this->put(route('admin.services.servers.import.select', $this->server), ['ticked' => true, 'client_id' => $mallory->id, 'product_id' => 21]);

    expect(ServerAccountImport::query()->sole()->selections['alpha'])->not->toHaveKey('client_id');
});

test('[S133-10] a choice changed after the review never reaches the import without a new review', function (array $change) {
    Queue::fake([ImportReviewedAccounts::class]);
    Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    $other = Client::factory()->create(['currency' => 'USD']);
    s133Staff();
    s133Fetch($this->server);
    s133Select($this->server, ['usernames' => ['alpha'], 'include' => true]);
    $digest = $this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('digest');

    s133Select($this->server, ['usernames' => ['alpha'], ...($change === ['client' => true] ? ['client_id' => $other->id] : $change)]);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasErrors('import');

    Queue::assertNothingPushed();
})->with([
    'cycle' => [['cycle' => 'annually']],
    'due date' => [['next_due_date' => '2027-03-01']],
    'client' => [['client' => true]],
    'bulk cycle' => [['ticked' => true, 'cycle' => 'annually']],
]);

test('[S133-11] a chosen due date that has passed by the time of the review is not imported as a past date', function () {
    Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    s133Staff();
    s133Fetch($this->server);
    s133Select($this->server, ['usernames' => ['alpha'], 'include' => true, 'next_due_date' => '2026-10-19']);

    CarbonImmutable::setTestNow('2026-10-20 09:00:00');
    confirmPassword();
    $alpha = s133Review($this->server)['alpha'];

    expect($alpha['decision']['problems'] !== [] || $alpha['decision']['next_due_date'] >= '2026-10-20')->toBeTrue();
});

// Continuing a stopped import

test('[S133-12] after a stop, the old confirmation starts nothing, and the next review imports only the rest, once', function () {
    Queue::fake([ImportReviewedAccounts::class]);
    $anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    s133Staff();
    s133Fetch($this->server);
    s133Select($this->server, ['usernames' => ['alpha', 'bravo'], 'include' => true, 'client_id' => $anna->id, 'product_id' => 21]);
    $digest = $this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('digest');
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasNoErrors();
    $import = ServerAccountImport::query()->sole();
    app(ImportServerAccounts::class)->handle($this->server, $import, auth()->user(), ['alpha']);
    (new ImportReviewedAccounts($import->id, auth()->id(), 1, $digest))->failed(new RuntimeException('worker died'));

    // The old confirmation, and the old chunk job replayed, do nothing.
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasErrors('import');
    (new ImportReviewedAccounts($import->id, auth()->id(), 0, $digest))->handle(app(ImportServerAccounts::class));

    // Ticking the imported account again does not bring it back.
    s133Select($this->server, ['usernames' => ['alpha'], 'include' => true, 'client_id' => $anna->id, 'product_id' => 21]);
    $rows = s133Review($this->server);
    expect($rows['alpha']['decision']['problems'])->not->toBeEmpty()
        ->and($rows['bravo']['decision']['problems'])->toBeEmpty();

    $next = $this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('digest');
    expect($next)->not->toBe($digest);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $next])->assertSessionHasNoErrors();
    $import->refresh();
    app(ImportServerAccounts::class)->handle($this->server, $import, auth()->user());

    expect(Service::query()->where('username', 'alpha')->count())->toBe(1)
        ->and(Service::query()->where('username', 'bravo')->count())->toBe(1);
});

test('[S133-13] Fetch again carries the report and choices only within the same server, and waits while an import runs', function () {
    Queue::fake([ImportReviewedAccounts::class]);
    $anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    $other = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 2', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $this->group->servers()->attach($other, ['sort_order' => 1]);
    s133Staff();
    s133Fetch($this->server);
    s133Fetch($other);
    s133Select($this->server, ['usernames' => ['alpha', 'bravo'], 'include' => true, 'client_id' => $anna->id, 'product_id' => 21]);
    $digest = $this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('digest');
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasNoErrors();

    // While importing, Fetch again is refused on this server.
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasErrors('import');

    $import = ServerAccountImport::query()->where('server_id', $this->server->id)->sole();
    app(ImportServerAccounts::class)->handle($this->server, $import, auth()->user(), ['alpha']);
    (new ImportReviewedAccounts($import->id, auth()->id(), 1, $digest))->failed(new RuntimeException('worker died'));

    s133Fetch($other);
    s133Fetch($this->server);

    $mine = ServerAccountImport::query()->where('server_id', $this->server->id)->sole();
    $theirs = ServerAccountImport::query()->where('server_id', $other->id)->sole();
    expect($theirs->imported_count + $theirs->not_imported_count)->toBe(0)
        ->and($theirs->selections)->toBeNull()
        ->and(collect($mine->reportLines())->pluck('username')->sort()->values()->all())->toBe(['alpha', 'bravo'])
        ->and(array_keys($mine->selections ?? []))->toBe(['bravo']);
});
