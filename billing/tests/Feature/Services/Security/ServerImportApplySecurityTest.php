<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Security review of #95 (Import accounts, step 3b: choose, review, import). Only accounts in the stored list, only
 * staff choices, only the client staff confirmed; apply is step-up, throttled, audited per row and not replayable.
 * Adopted; secApply() and the throttle case now send the review page's digest, which apply requires. The client
 * search case moves with the client picker to the next PR.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $this->group = ServerGroup::factory()->create();
    $this->group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200, 'annually' => 12000], driver: 'cpanel', serverGroupId: $this->group->id, driverConfig: ['package' => 'root_basic']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    $this->mallory = Client::factory()->create(['email' => 'mallory@example.net', 'currency' => 'USD']);

    // alpha: owner root, contact anna@alpha-books.com, package root_basic; bravo: owned by reseller1; charlie: no email.
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
    $this->staff = actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
});

function secSelect(Server $server, array $choice): void
{
    test()->put(route('admin.services.servers.import.select', $server), $choice)->assertSessionHasNoErrors();
}

/**
 * @return array<string, array<string, mixed>>
 */
function secReview(Server $server): array
{
    return collect(test()->get(route('admin.services.servers.import.review', $server))->assertOk()->inertiaProps('rows'))->keyBy('username')->all();
}

/**
 * Review, then confirm exactly what was reviewed (apply carries the review page's digest since this review).
 */
function secApply(Server $server): void
{
    $digest = test()->get(route('admin.services.servers.import.review', $server))->assertOk()->inertiaProps('digest');
    test()->post(route('admin.services.servers.import.apply', $server), ['digest' => $digest])->assertRedirect();
}

// Explicit confirmation of the client

test('a client that takes the account\'s contact email after staff reviewed the account does not receive it on apply', function () {
    secSelect($this->server, ['usernames' => ['alpha'], 'include' => true]);
    $review = $this->get(route('admin.services.servers.import.review', $this->server));
    expect(collect($review->inertiaProps('rows'))->keyBy('username')['alpha']['decision']['client']['id'])->toBe($this->anna->id);

    // Between review and apply: Anna changes her email, and Mallory sets hers (client area, no verification) to the
    // account's contact email. Staff confirm what they reviewed.
    $this->anna->update(['email' => 'anna@new-domain.example']);
    $this->mallory->update(['email' => 'anna@alpha-books.com']);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $review->inertiaProps('digest')])->assertRedirect();

    expect(Service::query()->where('username', 'alpha')->value('client_id'))->not->toBe($this->mallory->id);
});

test('a ticked account with no client at review is not imported onto a client that matches only later', function () {
    $this->anna->update(['email' => 'anna@elsewhere.example']);
    secSelect($this->server, ['usernames' => ['alpha'], 'include' => true]);
    $review = $this->get(route('admin.services.servers.import.review', $this->server));
    expect(collect($review->inertiaProps('rows'))->keyBy('username')['alpha']['decision']['problems'])->toContain('Choose a client.');

    // Staff confirm what they reviewed; the digest binds apply to it.
    $this->mallory->update(['email' => 'anna@alpha-books.com']);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $review->inertiaProps('digest')])->assertRedirect();

    expect(Service::query()->where('client_id', $this->mallory->id)->exists())->toBeFalse();
});

// What the request may carry

test('selections take only usernames in this server\'s stored list, and only the choice fields', function () {
    $other = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 2', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $this->group->servers()->attach($other, ['sort_order' => 1]);
    ServerAccountImport::query()->create(['server_id' => $other->id, 'status' => 'ready', 'accounts' => [], 'connection_user' => 'root', 'expires_at' => now()->addDay()]);

    secSelect($this->server, ['usernames' => ['alpha', 'ghost', '../../etc', 'ALPHA'], 'include' => true,
        'domain' => 'evil.example', 'username' => 'root', 'recurring_amount' => 0, 'status' => 'active', 'server_id' => $other->id, 'amount' => 1]);
    secSelect($other, ['usernames' => ['alpha'], 'include' => true]);

    expect(ServerAccountImport::query()->where('server_id', $this->server->id)->sole()->selections)->toBe(['alpha' => ['include' => true]])
        ->and(ServerAccountImport::query()->where('server_id', $other->id)->sole()->selections)->toBeEmpty();
});

test('a choice naming an inactive client, a one-time cycle or a client of another status is refused', function (string $status) {
    $client = Client::factory()->create(['status' => $status]);

    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true, 'client_id' => $client->id])
        ->assertSessionHasErrors('client_id');
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true, 'cycle' => 'onetime'])
        ->assertSessionHasErrors('cycle');
})->with(['inactive', 'closed']);

test('apply carries nothing: posted clients, usernames or amounts are ignored', function () {
    $this->post(route('admin.services.servers.import.apply', $this->server), [
        'usernames' => ['alpha', 'bravo'], 'client_id' => $this->mallory->id, 'selections' => ['alpha' => ['include' => true, 'client_id' => $this->mallory->id]],
    ])->assertRedirect();

    expect(Service::query()->count())->toBe(0);
});

test('a reseller-owned account with no chosen client, or a client made inactive since, is left out', function () {
    secSelect($this->server, ['usernames' => ['bravo'], 'include' => true]);
    secSelect($this->server, ['usernames' => ['alpha'], 'include' => true]);
    $this->anna->update(['status' => 'inactive']);

    secApply($this->server);

    expect(Service::query()->count())->toBe(0);
});

// Races, audit, step-up, replay

test('the live unique index catches a service created for the account between review and insert', function () {
    secSelect($this->server, ['usernames' => ['alpha'], 'include' => true]);
    $this->get(route('admin.services.servers.import.review', $this->server))->assertOk();
    $import = ServerAccountImport::query()->where('server_id', $this->server->id)->sole();
    expect($import->reviewed['rows'][0]['username'])->toBe('alpha');

    // The queued import creates the reviewed rows; a service created for the account in between makes the insert fail.
    $racer = Service::factory()->create(['server_id' => $this->server->id, 'username' => 'alpha', 'status' => ServiceStatus::Active]);
    $report = app(ImportServerAccounts::class)->handle($this->server, $import->refresh(), $this->staff);

    expect($report[0]['imported'])->toBeFalse()
        ->and(Service::query()->where('server_id', $this->server->id)->where('username', 'alpha')->pluck('id')->all())->toBe([$racer->id]);
});

test('each imported account is audited against the staff member, with the account in the properties', function () {
    secSelect($this->server, ['usernames' => ['alpha'], 'include' => true]);
    secApply($this->server);
    $service = Service::query()->where('username', 'alpha')->sole();

    $entry = ActivityLog::query()->where('subject_type', $service->getMorphClass())->where('subject_id', $service->id)->sole();
    expect($entry->user_id)->toBe($this->staff->id)
        ->and($entry->properties['username'] ?? null)->toBe('alpha');
});

test('apply needs a recent password and is throttled; review and selections need the exact permission', function () {
    secSelect($this->server, ['usernames' => ['alpha'], 'include' => true]);

    foreach ([['services.*'], ['services.servers.*']] as $permissions) {
        actingAsStaff($permissions);
        $this->get(route('admin.services.servers.import.review', $this->server))->assertForbidden();
        $this->put(route('admin.services.servers.import.select', $this->server), ['proposed' => true])->assertForbidden();
        $this->post(route('admin.services.servers.import.apply', $this->server))->assertForbidden();
    }

    $this->flushSession();
    actingAsStaff(['services.servers.import']);
    $this->post(route('admin.services.servers.import.apply', $this->server))->assertSessionHasErrors('password_confirmation');
    expect(Service::query()->count())->toBe(0);

    actingAsStaff(['services.servers.import']);
    confirmPassword();
    $digest = $this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('digest');
    foreach (range(1, 6) as $attempt) {
        $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertRedirect();
    }
    $this->post(route('admin.services.servers.import.apply', $this->server))->assertTooManyRequests();
    expect(Service::query()->count())->toBe(1);
});

test('replaying apply, or ticking an imported account again, never creates a second service', function () {
    secSelect($this->server, ['usernames' => ['alpha'], 'include' => true]);
    secApply($this->server);
    secApply($this->server);

    secSelect($this->server, ['usernames' => ['alpha'], 'include' => true]);
    secApply($this->server);

    expect(Service::query()->where('server_id', $this->server->id)->where('username', 'alpha')->count())->toBe(1);
});
