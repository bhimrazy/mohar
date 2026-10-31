<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Platform\Actions\Clients\MergeClients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Re-review of #520 (M2). R1 is red at e1a35743: merging clients is a third way to attach a login, and it does not
 * apply the verified-email rule. R2 guards "a password change keeps the session that made it"; C3 passes without it.
 */

beforeEach(function () {
    Currency::factory()->create();
});

function rr520StoredSession(User $user): string
{
    $id = Str::random(40);
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'probe', 'payload' => base64_encode(serialize([])), 'last_activity' => now()->getTimestamp()]);

    return $id;
}

test('R1: merging a self-registered duplicate does not give its unverified login the target account', function () {
    $corp = Client::factory()->withOwner()->create(['company_name' => 'Corp']);
    // Signed up with Corp's address before Corp did, never proved it; staff see a duplicate and merge it.
    $squatter = User::factory()->unverified()->create(['email' => 'accounts@corp.example', 'last_login_at' => now()]);
    $duplicate = Client::factory()->create(['company_name' => 'Corp', 'email' => 'accounts@corp.example', 'currency' => $corp->currency]);
    $duplicate->users()->attach($squatter, ['is_owner' => true]);

    try {
        app(MergeClients::class)->handle($duplicate, $corp);
    } catch (ValidationException) {
        // Refusing the merge is an acceptable fix.
    }

    expect(DB::table('client_user')->where('client_id', $corp->id)->where('user_id', $squatter->id)->exists())->toBeFalse();
});

test('R2: a password change keeps the session that made it and ends the others', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create(['password' => 'Old-correct-horse-battery']);
    $current = rr520StoredSession($user);
    $other = rr520StoredSession($user);

    $this->actingAs($user)->withCookie(config('session.cookie'), $current)
        ->put(route('user-password.update'), ['current_password' => 'Old-correct-horse-battery', 'password' => 'N3w-correct-horse-battery', 'password_confirmation' => 'N3w-correct-horse-battery'])
        ->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', $current)->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('id', $other)->exists())->toBeFalse();
});
