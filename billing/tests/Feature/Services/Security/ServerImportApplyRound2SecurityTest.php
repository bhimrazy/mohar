<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Jobs\ImportReviewedAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Security re-check of #95 (import step 3b-1): apply is bound to the review digest and runs on the queue. These pin
 * the 3b-1 scope (only proposed accounts, on their stored match; no client or product chosen by request) and that the
 * queued job imports what was confirmed, not what today's plan says. Adopted; R2-5 changes the review through the price,
 * since this step accepts no cycle in the request.
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

    // alpha: owner root, contact anna@alpha-books.com (proposed); bravo: owned by reseller1; charlie: no email (unmatched).
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
    $this->staff = actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
});

function r2Digest(Server $server): string
{
    return test()->get(route('admin.services.servers.import.review', $server))->assertOk()->inertiaProps('digest');
}

function r2RunQueued(): void
{
    Queue::pushed(ImportReviewedAccounts::class)->each(fn (ImportReviewedAccounts $job) => $job->handle(app(ImportServerAccounts::class)));
}

// 3b-1 scope: unmatched and reseller-owned accounts stay out; no client or product comes from the request.

test('[R2-1] an unmatched or reseller-owned account cannot be imported by naming a client and product in the request', function (string $username) {
    test()->put(route('admin.services.servers.import.select', $this->server), ['usernames' => [$username], 'include' => true, 'client_id' => $this->mallory->id, 'product_id' => 21]);
    test()->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r2Digest($this->server)]);

    expect(Service::query()->where('username', $username)->exists())->toBeFalse();
})->with(['unmatched' => 'charlie', 'reseller-owned' => 'bravo']);

test('[R2-2] a proposed account cannot be moved to another client by a crafted client_id', function () {
    test()->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true, 'client_id' => $this->mallory->id]);
    test()->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r2Digest($this->server)]);

    expect(Service::query()->where('client_id', $this->mallory->id)->exists())->toBeFalse();
});

// The queued job imports what was confirmed.

test('[R2-3] a price changed after apply was confirmed, before the queued chunk runs, is not imported at the new price', function () {
    Queue::fake([ImportReviewedAccounts::class]);
    test()->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true])->assertSessionHasNoErrors();
    test()->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r2Digest($this->server)])->assertSessionHasNoErrors();

    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1500], driver: 'cpanel', serverGroupId: $this->group->id, driverConfig: ['package' => 'root_basic']);
    r2RunQueued();

    expect(Service::query()->where('username', 'alpha')->value('recurring_amount'))->not->toBe(1500);
});

test('[R2-4] a selection written after the digest check (a PUT that passed ready() before the lock) does not re-target the confirmed client', function () {
    Queue::fake([ImportReviewedAccounts::class]);
    test()->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true])->assertSessionHasNoErrors();
    test()->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r2Digest($this->server)])->assertSessionHasNoErrors();

    // The racing PUT's write: select() read the list while it was ready, then saves after start() set it importing.
    $import = ServerAccountImport::query()->where('server_id', $this->server->id)->sole();
    $import->update(['selections' => ['alpha' => ['include' => true, 'client_id' => $this->mallory->id]]]);
    r2RunQueued();

    expect(Service::query()->where('username', 'alpha')->value('client_id'))->not->toBe($this->mallory->id);
});

// Defences that hold (regression pins).

test('[R2-5] a digest is bound to this server\'s own review: another server\'s, a stale or a forged one is refused', function () {
    // More applies than the shared per-user throttle bucket allows in a minute (see R2-8).
    $this->withoutMiddleware(ThrottleRequests::class);
    $other = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 2', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $this->group->servers()->attach($other, ['sort_order' => 1]);
    $this->post(route('admin.services.servers.import.fetch', $other))->assertSessionHasNoErrors();
    test()->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true])->assertSessionHasNoErrors();
    test()->put(route('admin.services.servers.import.select', $other), ['usernames' => ['alpha'], 'include' => true])->assertSessionHasNoErrors();
    $digestA = r2Digest($this->server);

    // Server B was never reviewed: A's digest does not open it.
    $this->post(route('admin.services.servers.import.apply', $other), ['digest' => $digestA])->assertSessionHasErrors('import');
    // A forged digest, and a stale one after the choices changed and were reviewed again.
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => str_repeat('0', 64)])->assertSessionHasErrors('import');
    // (3b-1 takes no cycle from the request, so the review changes through the price instead.)
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1300], driver: 'cpanel', serverGroupId: $this->group->id, driverConfig: ['package' => 'root_basic']);
    r2Digest($this->server);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digestA])->assertSessionHasErrors('import');

    expect(Service::query()->count())->toBe(0);
});

test('[R2-6] a second apply while importing is refused and dispatches nothing; choices, review and Match again wait too', function () {
    Queue::fake([ImportReviewedAccounts::class]);
    test()->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true])->assertSessionHasNoErrors();
    $digest = r2Digest($this->server);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasNoErrors();
    $reviewed = ServerAccountImport::query()->sole()->reviewed;

    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasErrors('import');
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'client_id' => $this->mallory->id])->assertSessionHasErrors('import');
    $this->get(route('admin.services.servers.import.review', $this->server))->assertSessionHasErrors('import');
    $this->post(route('admin.services.servers.import.rematch', $this->server))->assertSessionHasErrors('import');

    Queue::assertPushed(ImportReviewedAccounts::class, 1);
    expect(ServerAccountImport::query()->sole()->reviewed)->toBe($reviewed);
});

test('[R2-7] the reviewed and report columns cannot be written through selections', function () {
    $this->put(route('admin.services.servers.import.select', $this->server), [
        'usernames' => ['alpha'], 'include' => true,
        'reviewed' => ['digest' => 'x', 'rows' => [['username' => 'charlie', 'client_id' => $this->mallory->id]]], 'report' => [['username' => 'x']], 'status' => 'importing',
    ])->assertSessionHasNoErrors();

    $import = ServerAccountImport::query()->sole();
    expect($import->reviewed)->toBeNull()->and($import->report)->toBe([]) // report lines live in their own table since round 5->and($import->status)->toBe('ready')
        ->and($import->selections)->toBe(['alpha' => ['include' => true]]);
});

test('[R2-8] ticking accounts one at a time does not use up the apply throttle', function () {
    foreach (['alpha', 'bravo', 'charlie', 'alpha', 'bravo', 'charlie'] as $username) {
        $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => [$username], 'include' => $username === 'alpha'])->assertSessionHasNoErrors();
    }

    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r2Digest($this->server)])->assertSessionHasNoErrors()->assertRedirect();
    expect(Service::query()->where('username', 'alpha')->exists())->toBeTrue();
});

test('[R2-9] a client matched only for the moment a review was shown is not kept as the account\'s client', function () {
    $this->anna->update(['email' => 'anna@elsewhere.example']);
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true])->assertSessionHasNoErrors();

    // Mallory takes the contact email (client area, unverified); any GET of the review (a link in a ticket) pins her.
    $this->mallory->update(['email' => 'anna@alpha-books.com']);
    r2Digest($this->server);
    $this->mallory->update(['email' => 'mallory@example.net']);

    $rows = collect($this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('rows'))->keyBy('username');
    expect($rows['alpha']['decision']['problems'])->toContain('Choose a client.');
});
