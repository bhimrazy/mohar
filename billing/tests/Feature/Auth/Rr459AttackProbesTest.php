<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Re-review probes for #459, adopted: paths from an expired one-time password. Each asserts what ADR 0018 amendment
 * (#459) says; R5 and R6 document the accepted cases.
 */
function rr459ExpiringUser(): User
{
    return User::factory()->create(['password_expires_at' => now()->addMinute(), 'must_change_password' => true]);
}

test('R1: passkey registration (RequirePassword) refuses an expired one-time password in a session confirmed before it expired', function () {
    $user = rr459ExpiringUser();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    $this->travel(2)->minutes();
    expect($user->refresh()->passwordExpired())->toBeTrue();

    $this->getJson(route('passkey.registration-options'))->assertStatus(423);
});

test('R2: the security page (RequirePassword) refuses after expiry in a session confirmed before it', function () {
    $user = rr459ExpiringUser();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    $this->travel(2)->minutes();

    $this->get(route('security.edit'))->assertRedirect(route('password.confirm'));
});

test('R3: two-factor disable (RequirePassword) refuses after expiry in a session confirmed before it', function () {
    $user = User::factory()->withTwoFactor()->create(['password_expires_at' => now()->addMinute(), 'must_change_password' => true]);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    $this->travel(2)->minutes();

    $this->deleteJson(route('two-factor.disable'))->assertStatus(423);
    expect($user->refresh()->two_factor_secret)->not->toBeNull();
});

test('R4: the password change form does not take an expired one-time password as the current password', function () {
    $user = rr459ExpiringUser();
    $this->actingAs($user);
    $this->travel(2)->minutes();

    $this->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'Plum-Orbit-73-Lantern', 'password_confirmation' => 'Plum-Orbit-73-Lantern'])
        ->assertSessionHasErrors('current_password');
    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

test('R5: a remember-me cookie issued before the expiry still signs in after it (documents the accepted case)', function () {
    $user = rr459ExpiringUser();
    $user->forceFill(['remember_token' => 'window-token'])->save();
    $this->travel(2)->minutes();

    $this->withCookies([Auth::guard()->getRecallerName() => $user->id.'|window-token|'.$user->getAuthPassword()])->get(route('profile.edit'));
    $this->assertAuthenticatedAs($user);
});

test('R6: the refused two-factor completion still consumes the recovery code', function () {
    $user = User::factory()->withTwoFactor()->create(['password_expires_at' => now()->addMinute()]);
    $before = $user->recoveryCodes();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
    $this->travel(10)->minutes();

    $this->post(route('two-factor.login.store'), ['recovery_code' => $before[0]])->assertSessionHasErrors('email');
    $this->assertGuest();

    expect($user->refresh()->recoveryCodes())->not->toContain($before[0]);
});
