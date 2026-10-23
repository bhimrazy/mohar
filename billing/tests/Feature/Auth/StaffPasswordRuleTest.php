<?php

use App\Models\User;

/** ADR 0018 §5: a staff login always has a password, so staff step-up is always possible. */
it('refuses to save a staff login without a password', function () {
    expect(fn () => User::factory()->staff(['*'])->create(['password' => null]))->toThrow(DomainException::class);
});

it('refuses to promote a passwordless client login to staff', function () {
    $user = User::factory()->create(['password' => null]);

    expect($user->hasPassword())->toBeFalse();
    expect(fn () => $user->forceFill(['is_staff' => true])->save())->toThrow(DomainException::class);
    expect($user->fresh()->is_staff)->toBeFalse();
});

it('allows a passwordless client login and a promotion once it has a password', function () {
    $user = User::factory()->create(['password' => null]);

    $user->forceFill(['is_staff' => true, 'password' => 'a-Strong-pass-123!'])->save();

    expect($user->fresh()->is_staff)->toBeTrue();
});
