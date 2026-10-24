<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Security re-check of #95 (import step 3b-1), round 3. R3-1 and R3-2: the plan matches each ticked account again with
 * the stored connection_user, so a list stored without one (fetched before 2026_10_19_400450; #101 re-review R4)
 * compares no owners, and a reseller-owned account becomes importable onto the client its contact email matches.
 * R3-3 to R3-5 pin defences that hold.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200], driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_basic']);
    $this->kit->priceBook->addProduct(22, 'Reseller starter', ['monthly' => 900], driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'reseller1_starter']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    $this->chef = Client::factory()->create(['email' => 'chef@bravo-cafe.com', 'currency' => 'USD']);

    // alpha: owner root (proposed); bravo: owned by reseller1 (reseller); charlie: no contact email (unmatched).
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
    actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    $this->import = ServerAccountImport::query()->where('server_id', $this->server->id)->sole();
});

function r3Digest(Server $server): string
{
    return test()->get(route('admin.services.servers.import.review', $server))->assertOk()->inertiaProps('digest');
}

test('[R3-1] on a list stored without connection_user, a reseller-owned account ticked by request is still kept out', function () {
    expect(collect($this->import->accountRows())->keyBy('username')['bravo']['category'])->toBe('reseller');
    DB::table('server_account_imports')->where('id', $this->import->id)->update(['connection_user' => null]);

    // No client or product in the request: only the tick.
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['bravo'], 'include' => true])->assertSessionHasNoErrors();
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r3Digest($this->server)]);

    expect(Service::query()->where('username', 'bravo')->exists())->toBeFalse();
});

test('[R3-2] after Match again on a list without connection_user, "Select all proposed" does not import a reseller\'s account', function () {
    DB::table('server_account_imports')->where('id', $this->import->id)->update(['connection_user' => null]);
    $this->post(route('admin.services.servers.import.rematch', $this->server))->assertSessionHasNoErrors();

    // The normal UI path: tick every proposed account, review, confirm.
    $this->put(route('admin.services.servers.import.select', $this->server), ['proposed' => true])->assertSessionHasNoErrors();
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => r3Digest($this->server)]);

    expect(Service::query()->where('username', 'bravo')->exists())->toBeFalse();
});

test('[R3-3] an account ticked after the review is not imported by the confirmed import', function () {
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true])->assertSessionHasNoErrors();
    $digest = r3Digest($this->server);
    DB::table('server_account_imports')->where('id', $this->import->id)->update(['connection_user' => 'root']);
    $import = $this->import->fresh();
    // A second account ticked once the review was shown (as a PUT would, without a new review).
    $import->update(['selections' => [...$import->selections, 'bravo' => ['include' => true, 'client_id' => $this->chef->id, 'product_id' => 22]]]);

    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasNoErrors();

    expect(Service::query()->pluck('username')->all())->toBe(['alpha']);
});

test('[R3-4] a digest reviewed on a list that was fetched again does not start an import of the new list', function () {
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true])->assertSessionHasNoErrors();
    $digest = r3Digest($this->server);
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true])->assertSessionHasNoErrors();

    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasErrors('import');
    expect(Service::query()->count())->toBe(0);
});

test('[R3-5] an unmatched account ticked by request, with no client chosen, is shown as excluded and not imported', function () {
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['charlie'], 'include' => true])->assertSessionHasNoErrors();
    $review = $this->get(route('admin.services.servers.import.review', $this->server))->assertOk();

    expect($review->inertiaProps('counts.ready'))->toBe(0);
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $review->inertiaProps('digest')])->assertSessionHasErrors('import');
    expect(Service::query()->count())->toBe(0);
});
