<?php

use App\Modules\Core\Enums\ClientOrigin;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Actions\RegisterCheckoutClient;
use Illuminate\Support\Facades\DB;

/**
 * Each path that creates a client records how (clients.origin), and the upgrade backfill marks staff-created only the
 * clients the audit log shows staff creating.
 */
beforeEach(function () {
    Currency::factory()->create();
});

test('sign-up, checkout and the admin form each record their origin', function () {
    $this->post(route('register'), ['name' => 'Sam Signup', 'email' => 'sam@example.com', 'password' => 'Correct-Horse-9-Battery', 'password_confirmation' => 'Correct-Horse-9-Battery']);
    [, $checkout] = app(RegisterCheckoutClient::class)->handle(['first_name' => 'Cara', 'last_name' => 'Checkout', 'email' => 'cara@example.com', 'password' => 'Correct-Horse-9-Battery'], 'USD');
    auth()->logout();
    actingAsStaff(['core.clients.*']);
    $this->post(route('admin.clients.store'), ['first_name' => 'Stan', 'last_name' => 'Staff', 'email' => 'stan@example.com', 'currency' => 'USD', 'status' => 'active', 'tax_exempt' => false, 'origin' => 'import'])->assertRedirect();

    expect(Client::query()->where('email', 'sam@example.com')->sole()->origin)->toBe(ClientOrigin::SignUp)
        ->and($checkout->refresh()->origin)->toBe(ClientOrigin::Checkout)
        ->and(Client::query()->where('email', 'stan@example.com')->sole()->origin)->toBe(ClientOrigin::Staff);
});

test('the backfill marks staff-created only clients the audit log shows staff creating; the rest stay sign-up', function () {
    $byStaff = Client::factory()->create();
    $unknown = Client::factory()->create();
    $byClient = Client::factory()->create();
    DB::table('clients')->update(['origin' => ClientOrigin::SignUp->value]);
    ActivityLog::query()->create(['client_id' => $byStaff->id, 'description' => 'Client created', 'event' => 'client.created', 'actor_type' => 'staff']);
    ActivityLog::query()->create(['client_id' => $byClient->id, 'description' => 'Client created', 'event' => 'client.created', 'actor_type' => 'user']);

    (require database_path('../app/Modules/Core/Database/Migrations/2026_10_20_000190_add_origin_to_clients.php'))->backfill();

    expect($byStaff->refresh()->origin)->toBe(ClientOrigin::Staff)
        ->and($unknown->refresh()->origin)->toBe(ClientOrigin::SignUp)
        ->and($byClient->refresh()->origin)->toBe(ClientOrigin::SignUp);
});
