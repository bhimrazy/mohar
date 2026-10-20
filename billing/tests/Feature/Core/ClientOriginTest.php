<?php

use App\Models\User;
use App\Modules\Core\Enums\ClientOrigin;
use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Orders\Actions\RegisterCheckoutClient;
use Illuminate\Support\Facades\DB;

/**
 * Each path that creates a client records how (clients.origin), and each path that sets its email records who
 * (clients.email_source). The upgrade backfill trusts staff only where the activity log shows them.
 */
beforeEach(function () {
    Currency::factory()->create();
});

test('sign-up, checkout and the admin form each record the origin and who set the email', function () {
    $this->post(route('register'), ['name' => 'Sam Signup', 'email' => 'sam@example.com', 'password' => 'Correct-Horse-9-Battery', 'password_confirmation' => 'Correct-Horse-9-Battery']);
    [, $checkout] = app(RegisterCheckoutClient::class)->handle(['first_name' => 'Cara', 'last_name' => 'Checkout', 'email' => 'cara@example.com', 'password' => 'Correct-Horse-9-Battery'], 'USD');
    auth()->logout();
    actingAsStaff(['core.clients.*']);
    $this->post(route('admin.clients.store'), ['first_name' => 'Stan', 'last_name' => 'Staff', 'email' => 'stan@example.com', 'currency' => 'USD', 'status' => 'active', 'tax_exempt' => false, 'origin' => 'import', 'email_source' => 'import'])->assertRedirect();

    $signup = Client::query()->where('email', 'sam@example.com')->sole();
    $staff = Client::query()->where('email', 'stan@example.com')->sole();
    expect([$signup->origin, $signup->email_source])->toBe([ClientOrigin::SignUp, EmailSource::Client])
        ->and([$checkout->refresh()->origin, $checkout->email_source])->toBe([ClientOrigin::Checkout, EmailSource::Client])
        ->and([$staff->origin, $staff->email_source])->toBe([ClientOrigin::Staff, EmailSource::Staff]);
});

test('an email changed in the client area is the client\'s; one changed by staff is staff\'s; other edits keep the source', function () {
    $client = Client::factory()->create();
    $login = User::factory()->create();
    $client->users()->attach($login, ['is_owner' => true]);
    $details = ['first_name' => 'A', 'last_name' => 'B', 'address_1' => '1 Road', 'city' => 'Town', 'postcode' => '12345', 'country' => 'US'];

    $this->actingAs($login)->withSession([ClientContext::SESSION_KEY => $client->id])
        ->put(route('client.account.update'), [...$details, 'email' => $client->email, 'email_source' => 'staff'])->assertSessionHasNoErrors();
    expect($client->refresh()->email_source)->toBe(EmailSource::Staff);

    $this->put(route('client.account.update'), [...$details, 'email' => 'mine@example.com'])->assertSessionHasNoErrors();
    expect($client->refresh()->email_source)->toBe(EmailSource::Client);

    auth()->logout();
    actingAsStaff(['core.clients.*']);
    $this->put(route('admin.clients.update', $client), ['first_name' => 'A', 'last_name' => 'B', 'email' => 'mine@example.com', 'currency' => 'USD', 'status' => 'active', 'tax_exempt' => false])->assertSessionHasNoErrors();
    expect($client->refresh()->email_source)->toBe(EmailSource::Client);

    $this->put(route('admin.clients.update', $client), ['first_name' => 'A', 'last_name' => 'B', 'email' => 'checked@example.com', 'currency' => 'USD', 'status' => 'active', 'tax_exempt' => false])->assertSessionHasNoErrors();
    expect($client->refresh()->email_source)->toBe(EmailSource::Staff);
});

test('the backfill trusts staff only where the activity log shows staff creating the client or setting its email last', function () {
    [$created, $unknown, $byClient, $edited, $changedLater] = Client::factory()->count(5)->create()->all();
    DB::table('clients')->update(['origin' => ClientOrigin::SignUp->value, 'email_source' => EmailSource::Client->value]);
    $log = fn (Client $client, array $entry) => ActivityLog::query()->create(['client_id' => $client->id, 'description' => 'Client created', ...$entry]);
    $log($created, ['event' => 'client.created', 'actor_type' => 'staff']);
    $log($byClient, ['event' => 'client.created', 'actor_type' => 'user']);
    $log($edited, ['event' => 'client.updated', 'actor_type' => 'staff', 'properties' => ['changed' => ['email']]]);
    $log($changedLater, ['event' => 'client.created', 'actor_type' => 'staff']);
    $log($changedLater, ['description' => 'Client updated account details', 'actor_type' => 'user']);

    (require base_path('app/Modules/Core/Database/Migrations/2026_10_20_000190_add_origin_to_clients.php'))->backfill();

    $state = fn (Client $client): array => [$client->refresh()->origin, $client->email_source];
    expect($state($created))->toBe([ClientOrigin::Staff, EmailSource::Staff])
        ->and($state($unknown))->toBe([ClientOrigin::SignUp, EmailSource::Client])
        ->and($state($byClient))->toBe([ClientOrigin::SignUp, EmailSource::Client])
        ->and($state($edited))->toBe([ClientOrigin::SignUp, EmailSource::Staff])
        ->and($state($changedLater))->toBe([ClientOrigin::Staff, EmailSource::Client]);
});
