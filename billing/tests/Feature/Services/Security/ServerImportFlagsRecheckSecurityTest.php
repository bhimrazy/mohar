<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Jobs\ImportReviewedAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Security re-check of #133 at 0fe00402: the client flags (AccountImportMatcher::clientFlags()). [S133R-n]
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200], driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_basic']);
    // alpha: owner root, contact anna@alpha-books.com, package root_basic.
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => Whm::fixture('listaccts_page1')['data']['acct']]])]);
});

function s133rSignUpAndMoveLogin(): Client
{
    test()->post(route('register'), ['name' => 'Anna Alpha', 'email' => 'anna@alpha-books.com', 'password' => 'Correct-Horse-9-Battery', 'password_confirmation' => 'Correct-Horse-9-Battery']);
    // The owner knows the password: an email change needs step-up (#388).
    confirmPassword();
    test()->patch(route('profile.update'), ['name' => 'Anna Alpha', 'email' => 'visitor@own.example'])->assertSessionHasNoErrors();
    // The visitor verifies the address they control.
    $login = User::query()->where('email', 'visitor@own.example')->sole();
    $login->forceFill(['email_verified_at' => now()])->save();
    test()->actingAs($login->refresh());

    return Client::query()->where('email', 'anna@alpha-books.com')->sole();
}

function s133rProposeAll(Server $server): ServerAccountImport
{
    actingAsStaff();
    confirmPassword();
    test()->post(route('admin.services.servers.import.fetch', $server))->assertSessionHasNoErrors();
    test()->put(route('admin.services.servers.import.select', $server), ['proposed' => true]);

    return ServerAccountImport::query()->sole();
}

test('[S133R-1] a sign-up whose owner deletes their own login does not start to look staff-created', function () {
    $client = s133rSignUpAndMoveLogin();
    $this->delete(route('profile.destroy'), ['password' => 'Correct-Horse-9-Battery'])->assertSessionHasNoErrors();
    expect($client->users()->count())->toBe(0);

    $import = s133rProposeAll($this->server);

    expect(collect($import->accounts)->keyBy('username')['alpha']['category'])->not->toBe('proposed')
        ->and($import->selections['alpha']['include'] ?? false)->toBeFalse();
});

test('[S133R-2] a sign-up whose login moved to another address stays flagged after the 30-day window', function () {
    s133rSignUpAndMoveLogin();
    CarbonImmutable::setTestNow('2026-12-01 10:00:00');

    $import = s133rProposeAll($this->server);

    expect(collect($import->accounts)->keyBy('username')['alpha']['category'])->toBe('unmatched')
        ->and($import->selections['alpha']['include'] ?? false)->toBeFalse();
});

test('[S133R-3] a flag that appears after the review fails the confirmation', function () {
    Queue::fake([ImportReviewedAccounts::class]);
    $anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    s133rProposeAll($this->server);
    $digest = $this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('digest');

    // The client's address changes and changes back: same email, a fresh change date.
    $anna->update(['email' => 'anna@elsewhere.example']);
    $anna->update(['email' => 'anna@alpha-books.com']);

    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasErrors('import');
    Queue::assertNothingPushed();
});
