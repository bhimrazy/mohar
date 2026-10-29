<?php

/*
 * Security round 2026-10-29, secrets track. Fortify registers POST /user/confirm-password with no limiter, so a
 * hijacked session can guess the password without limit and then pass every password.recent step-up (Show password,
 * gateway and server secrets, email change).
 */

use App\Models\User;

test('confirm-password is rate limited, so a hijacked session cannot guess its way past step-up', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->actingAs($user);

    foreach (range(1, 10) as $i) {
        $this->postJson(route('password.confirm.store'), ['password' => 'wrong-guess-'.$i]);
    }

    // The right password after ten wrong guesses within a minute must not confirm.
    $this->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])->assertStatus(429);
    expect(session('auth.password_confirmed_at'))->toBeNull();
});
