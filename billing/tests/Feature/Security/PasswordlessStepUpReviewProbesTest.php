<?php

use App\Contracts\Auth\SignInMethodCounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/** Review probes for PR #384 (hook bypasses, empty or null hashes, step-up and sign-in with no password). */
beforeEach(function () {
    Route::middleware(['web', 'auth', 'password.recent'])->post('/_rb384', fn () => response('done'));
});

function rb384Passwordless(): User
{
    return User::factory()->create(['password' => null]);
}

it('lets the hook be bypassed only by query-builder writes and saveQuietly, which cannot escalate', function () {
    $staff = User::factory()->staff(['*'])->create();
    User::query()->whereKey($staff->id)->update(['password' => null]);
    expect($staff->fresh()->hasPassword())->toBeFalse();

    $client = rb384Passwordless();
    $client->forceFill(['is_staff' => true])->saveQuietly();
    expect($client->fresh()->is_staff)->toBeTrue();
});

it('refuses nulling a staff password, and lets an unrelated save of an already passwordless staff login pass', function () {
    $staff = User::factory()->staff(['*'])->create();
    expect(fn () => $staff->forceFill(['password' => null])->save())->toThrow(DomainException::class);

    DB::table('users')->where('id', $staff->id)->update(['password' => null]);
    $staff = $staff->fresh();
    $staff->forceFill(['name' => 'x', 'email' => 'new@example.test'])->save();

    expect($staff->fresh()->name)->toBe('x');
});

it('treats an empty-string password as no password everywhere', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['password' => '']);
    $user = $user->fresh();

    expect($user->hasPassword())->toBeFalse()
        ->and(Hash::check('', $user->password))->toBeFalse()
        ->and(Hash::check('x', null))->toBeFalse();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->postJson('/_rb384')
        ->assertStatus(423)->assertJson(['code' => 'password_not_set']);
});

it('cannot sign in with a null password, and the password update still needs a current password', function () {
    $user = rb384Passwordless();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => ''])->assertSessionHasErrors();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors();
    $this->assertGuest();

    $this->actingAs($user)->put(route('user-password.update'), ['current_password' => '', 'password' => 'a-Strong-pass-123!', 'password_confirmation' => 'a-Strong-pass-123!'])
        ->assertSessionHasErrors('current_password');
    expect($user->fresh()->hasPassword())->toBeFalse();
});

it('keeps the confirmed timestamp valid for a login with a password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/_rb384')->assertOk();
});

it('leaves a passwordless login with no sign-in method counted once the counter exists (ADR 0018 §8)', function () {
    expect(app(SignInMethodCounter::class)->count(rb384Passwordless()))->toBe(0);
})->skip(! interface_exists(SignInMethodCounter::class), 'counter not in develop');
