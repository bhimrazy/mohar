<?php

use App\Models\User;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Hash;

/** A bcrypt hasher that counts password verifications. */
function countingHasher(): object
{
    $hasher = new class(['rounds' => 4]) extends BcryptHasher
    {
        public int $checks = 0;

        public function check($value, $hashedValue, array $options = []): bool
        {
            $this->checks++;

            return parent::check($value, $hashedValue, $options);
        }
    };
    Hash::forgetDrivers();
    Hash::extend('bcrypt', fn () => $hasher);

    return $hasher;
}

test('a sign-in verifies the password once, not once per pipeline step', function () {
    $user = User::factory()->create();
    $hasher = countingHasher();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($hasher->checks)->toBe(1);
});

test('a wrong password is still verified, rejected and counted against the throttle', function () {
    $user = User::factory()->create();
    $hasher = countingHasher();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($hasher->checks)->toBe(1);
});
