<?php

use App\Actions\Fortify\SignInValidatedUser;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Fortify;

/**
 * Where an expired one-time password is refused (#60, ADR 0018 amendment #459): at both password sign-in call sites
 * of RefuseExpiredPassword, at the end of a two-factor challenge, and at confirm-password step-up.
 */
const EXPIRED_OTP_MESSAGE = 'This one-time password has expired. Use "Forgot password", or ask the operator to run php artisan app:admin-password on the server.';

test('an expired one-time password is refused before the two-factor challenge, and the refusal is logged without secrets', function () {
    $user = User::factory()->withTwoFactor()->create(['password_expires_at' => now()->subMinute()]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors(['email' => EXPIRED_OTP_MESSAGE]);

    $this->assertGuest();
    expect(session('login.id'))->toBeNull();

    $log = ActivityLog::query()->where('event', 'auth.password_expired')->sole();
    expect($log->subject_id)->toBe($user->id)
        ->and($log->properties)->toBe(['stage' => 'sign_in'])
        ->and(json_encode($log->getAttributes()))->not->toContain('password"');
});

test('the fallback sign-in step refuses an expired one-time password and leaves nobody signed in', function () {
    $user = User::factory()->create(['password_expires_at' => now()->subMinute()]);
    // A pipeline without the two-factor step: SignInValidatedUser verifies the password itself.
    $callback = Fortify::$authenticateThroughCallback;
    Fortify::authenticateThrough(fn (): array => [SignInValidatedUser::class, PrepareAuthenticatedSession::class]);

    try {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors(['email' => EXPIRED_OTP_MESSAGE]);
    } finally {
        Fortify::$authenticateThroughCallback = $callback;
    }

    $this->assertGuest();
});

test('a two-factor challenge started before the expiry is refused when completed after it (#459)', function () {
    $user = User::factory()->withTwoFactor()->create(['password_expires_at' => now()->addMinute()]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
    $this->travel(10)->minutes();

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => EXPIRED_OTP_MESSAGE]);

    $this->assertGuest();
    expect(session('login.id'))->toBeNull()
        ->and(ActivityLog::query()->where('event', 'auth.password_expired')->sole()->properties)->toBe(['stage' => 'two_factor']);
});

test('step-up refuses an expired one-time password, even in a session confirmed before it expired (#459)', function () {
    $user = User::factory()->create(['password_expires_at' => now()->addMinute()]);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    $body = ['name' => $user->name, 'email' => 'new-address@example.com.np'];

    $this->travel(2)->minutes();

    $this->patchJson(route('profile.update'), $body)->assertStatus(423);
    $this->postJson(route('password.confirm.store'), ['password' => 'password'])->assertJsonValidationErrors(['password' => EXPIRED_OTP_MESSAGE]);
    $this->patchJson(route('profile.update'), $body)->assertStatus(423);

    expect($user->refresh()->email)->not->toBe('new-address@example.com.np');
});

test('deleting the account refuses an expired one-time password and keeps the account (#459)', function () {
    $user = User::factory()->create(['password_expires_at' => now()->addMinute()]);
    $this->actingAs($user);

    $this->travel(2)->minutes();

    $this->from(route('profile.edit'))->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['password' => EXPIRED_OTP_MESSAGE]);

    expect($user->fresh())->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});
