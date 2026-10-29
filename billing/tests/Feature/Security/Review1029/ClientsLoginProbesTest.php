<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

beforeEach(function () {
    Currency::factory()->create();
});

/** A stored database session for the login, as a signed-in browser would hold. */
function review1029StoredSession(User $user): string
{
    $id = Str::random(40);
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'probe', 'payload' => base64_encode(serialize([])), 'last_activity' => now()->getTimestamp()]);

    return $id;
}

test('C1: staff adding an address held by a login that never verified it does not give that login the account', function () {
    Notification::fake();
    confirmPassword();
    actingAsStaff(['core.users.manage']);
    $client = Client::factory()->withOwner()->create();

    // Someone signed up with the address before the real owner did, and never proved it.
    $squatter = User::factory()->unverified()->create(['email' => 'accounts@corp.example']);

    $this->post(route('admin.platform.clients.users.store', $client), ['email' => 'accounts@corp.example', 'name' => 'Accounts', 'permissions' => ['billing', 'support']]);

    expect($client->users()->whereKey($squatter->id)->exists())->toBeFalse();
});

test('C2: a password reset ends the login\'s other stored sessions', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    review1029StoredSession($user);
    $token = Password::broker()->createToken($user);

    $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'N3w-correct-horse-battery', 'password_confirmation' => 'N3w-correct-horse-battery'])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('C3: changing the password in settings ends the login\'s other stored sessions', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create(['password' => 'Old-correct-horse-battery']);
    review1029StoredSession($user);

    $this->actingAs($user)->put(route('user-password.update'), ['current_password' => 'Old-correct-horse-battery', 'password' => 'N3w-correct-horse-battery', 'password_confirmation' => 'N3w-correct-horse-battery'])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->count())->toBe(0);
});
