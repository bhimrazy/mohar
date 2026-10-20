<?php

use App\Models\User;
use App\Modules\Core\Enums\ClientOrigin;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Platform\Actions\Clients\MergeClients;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Review board for #153 (clients.origin): ways an address the client chose themselves is still trusted by the
 * import, and the paths that must not change a client's origin. [S153-n]
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

function s153ProposeAll(Server $server): ServerAccountImport
{
    actingAsStaff();
    confirmPassword();
    test()->post(route('admin.services.servers.import.fetch', $server))->assertSessionHasNoErrors();
    test()->put(route('admin.services.servers.import.select', $server), ['proposed' => true]);

    return ServerAccountImport::query()->sole();
}

/**
 * A client-area login of a staff-created client sets the client's email to the WHM contact's address.
 */
function s153ClientSetsEmail(Client $client, User $login): void
{
    test()->actingAs($login)->withSession([ClientContext::SESSION_KEY => $client->id])
        ->put(route('client.account.update'), [
            'first_name' => $client->first_name, 'last_name' => $client->last_name, 'email' => 'anna@alpha-books.com',
            'address_1' => '1 Road', 'city' => 'Town', 'postcode' => '12345', 'country' => 'US',
        ])->assertSessionHasNoErrors();
    auth()->logout();
}

test('[S153-1] a non-owner login with the profile area sets a staff-created client\'s email; after 30 days it is still not proposed', function () {
    // Staff create the client and invite a login: invitations are always non-owner (ManageClientLogins::invite).
    $client = Client::factory()->create(['email' => 'mallory@own.example', 'currency' => 'USD']);
    $login = User::factory()->create(['email' => 'mallory@own.example']);
    $client->users()->attach($login, ['is_owner' => false, 'permissions' => json_encode(['profile', 'services'])]);

    s153ClientSetsEmail($client, $login);
    expect($client->refresh()->email)->toBe('anna@alpha-books.com')->and($client->origin)->toBe(ClientOrigin::Staff);

    CarbonImmutable::setTestNow('2026-11-25 10:00:00');
    $import = s153ProposeAll($this->server);

    // The address is the client's own choice, verified by nobody: it must not be proposed and ticked in bulk.
    expect($import->selections['alpha']['include'] ?? false)->toBeFalse();
});

test('[S153-2] the owner of a staff-created client sets its email, waits out the window and deletes their login; it is still not proposed', function () {
    $client = Client::factory()->create(['email' => 'mallory@own.example', 'currency' => 'USD']);
    $login = User::factory()->create(['email' => 'mallory@own.example', 'password' => 'Correct-Horse-9-Battery']);
    $client->users()->attach($login, ['is_owner' => true]);

    s153ClientSetsEmail($client, $login);
    CarbonImmutable::setTestNow('2026-11-25 10:00:00');
    $this->actingAs($login)->delete(route('profile.destroy'), ['password' => 'Correct-Horse-9-Battery'])->assertSessionHasNoErrors();
    expect($client->users()->count())->toBe(0);

    $import = s153ProposeAll($this->server);

    expect($import->selections['alpha']['include'] ?? false)->toBeFalse();
});

test('[S153-3] origin cannot be posted through the client-area account form or the admin edit form', function () {
    [$user, $client] = [User::factory()->create(), Client::factory()->create()];
    $client->forceFill(['origin' => ClientOrigin::SignUp])->save();
    $client->users()->attach($user, ['is_owner' => true]);

    $this->actingAs($user)->withSession([ClientContext::SESSION_KEY => $client->id])->put(route('client.account.update'), [
        'first_name' => 'A', 'last_name' => 'B', 'email' => $client->email, 'address_1' => '1 Road', 'city' => 'Town',
        'postcode' => '12345', 'country' => 'US', 'origin' => 'staff',
    ])->assertSessionHasNoErrors();
    expect($client->refresh()->origin)->toBe(ClientOrigin::SignUp);

    auth()->logout();
    actingAsStaff(['core.clients.*']);
    $this->put(route('admin.clients.update', $client), [
        'first_name' => 'A', 'last_name' => 'B', 'email' => $client->email, 'currency' => 'USD', 'status' => 'active',
        'tax_exempt' => false, 'origin' => 'staff',
    ])->assertSessionHasNoErrors();
    expect($client->refresh()->origin)->toBe(ClientOrigin::SignUp);

    // Origin is not mass-assignable (strict models throw outside production), and a path that forgets it gets the
    // cautious default.
    expect(fn () => Client::query()->create(['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.com', 'currency' => 'USD', 'origin' => 'staff']))
        ->toThrow(MassAssignmentException::class);
    $forgot = Client::query()->create(['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.com', 'currency' => 'USD']);
    expect($forgot->refresh()->origin)->toBe(ClientOrigin::SignUp);
});

test('[S153-4] merging keeps each client\'s origin: a sign-up merged into a staff client does not make it, or the target, staff', function () {
    $staff = Client::factory()->create(['currency' => 'USD']);
    $signup = Client::factory()->create(['currency' => 'USD']);
    $signup->forceFill(['origin' => ClientOrigin::SignUp])->save();

    app(MergeClients::class)->handle($staff, $signup);

    expect($signup->refresh()->origin)->toBe(ClientOrigin::SignUp)
        ->and($staff->refresh()->origin)->toBe(ClientOrigin::Staff);
});
