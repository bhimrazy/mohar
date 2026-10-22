<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;

/**
 * Security review 2026-10-19, M2 (probe P3, inverted): registration is throttled per IP and per address, token
 * submission per IP only (so nobody can lock a victim out of a valid link), and answers are the same for known and
 * unknown addresses.
 */
function registerAttempt(string $email, string $ip = '198.51.100.7'): TestResponse
{
    // A mismatched confirmation keeps every attempt a guest: the limiter counts it before validation runs.
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->post(route('register.store'), [
        'name' => 'Script',
        'email' => $email,
        'password' => 'Password-123!',
        'password_confirmation' => 'different',
    ]);
}

function resetAttempt(string $email, string $ip = '198.51.100.7'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->post(route('password.update'), [
        'token' => 'not-a-real-token',
        'email' => $email,
        'password' => 'Password-123!',
        'password_confirmation' => 'Password-123!',
    ]);
}

test('probe P3: registrations from one IP are throttled after ten an hour', function () {
    foreach (range(1, 10) as $attempt) {
        registerAttempt("signup{$attempt}@example.com")->assertSessionHasErrors('password');
    }

    registerAttempt('signup11@example.com')->assertTooManyRequests();
    registerAttempt('signup12@example.com', '198.51.100.8')->assertSessionHasErrors('password');
});

test('registrations for one address are throttled across IPs, the same for a known and an unknown address', function () {
    $this->freezeTime();
    User::factory()->create(['email' => 'known@example.com']);

    foreach (range(1, 5) as $attempt) {
        registerAttempt('known@example.com', "203.0.113.{$attempt}")->assertSessionHasErrors('email');
        registerAttempt('UNKNOWN@example.com', "203.0.113.{$attempt}")->assertSessionHasErrors('password');
    }

    $known = registerAttempt('known@example.com', '203.0.113.99')->assertTooManyRequests();
    $unknown = registerAttempt('unknown@example.com', '203.0.113.99')->assertTooManyRequests();

    expect($known->headers->get('Retry-After'))->toBe($unknown->headers->get('Retry-After'));
});

test('password resets from one IP are throttled after five a minute', function () {
    foreach (range(1, 5) as $attempt) {
        resetAttempt("reset{$attempt}@example.com")->assertSessionHasErrors('email');
    }

    resetAttempt('reset6@example.com')->assertTooManyRequests();
});

test('failed resets for an address from other IPs never block its owner\'s valid link', function () {
    $victim = User::factory()->create(['email' => 'victim@example.com']);
    $token = Password::broker()->createToken($victim);

    foreach (range(1, 15) as $attempt) {
        resetAttempt('victim@example.com', "203.0.113.{$attempt}")->assertSessionHasErrors('email');
    }

    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.50'])->post(route('password.update'), [
        'token' => $token,
        'email' => 'victim@example.com',
        'password' => 'New-Password-123!',
        'password_confirmation' => 'New-Password-123!',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect(Hash::check('New-Password-123!', $victim->refresh()->password))->toBeTrue();
});

test('reset-link requests for one address stay throttled across IPs', function () {
    foreach (range(1, 5) as $attempt) {
        test()->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$attempt}"])->post(route('password.email'), ['email' => 'target@example.com'])->assertSessionHasNoErrors();
    }

    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->post(route('password.email'), ['email' => 'target@example.com'])->assertTooManyRequests();
});

test('a failed password reset says the same for a known and an unknown address', function () {
    $known = User::factory()->create(['email' => 'known@example.com']);

    $message = 'This password reset link is invalid or has expired. Request a new one.';

    resetAttempt($known->email, '203.0.113.1')->assertSessionHasErrors(['email' => $message]);
    resetAttempt('nobody@example.com', '203.0.113.2')->assertSessionHasErrors(['email' => $message]);
});
