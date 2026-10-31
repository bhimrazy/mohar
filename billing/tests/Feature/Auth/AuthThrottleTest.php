<?php

use App\Actions\Fortify\PasswordGuesses;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
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

/**
 * Security review 2026-10-29, M1, and #522: wrong current passwords (step-up, password change, account deletion)
 * share one budget per login, five a minute per IP and twenty an hour from any IP; correct ones never count.
 */
test('confirm-password allows five guesses a minute per login and IP, then answers 429 with the wait', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->actingAs($user);

    foreach (range(1, PasswordGuesses::PER_MINUTE) as $attempt) {
        $this->postJson(route('password.confirm.store'), ['password' => 'wrong-'.$attempt])->assertUnprocessable();
    }

    $this->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('errors.password.0', fn (string $message): bool => str_starts_with($message, 'Too many password attempts. Try again in'));
    expect(session('auth.step_up_confirmed_at'))->toBeNull();

    // Another login on the same IP keeps its own budget.
    $this->actingAs(User::factory()->create(['password' => 'other-login-password']))
        ->postJson(route('password.confirm.store'), ['password' => 'other-login-password'])->assertCreated();
});

test('the full-page confirm form shows the throttle message on its password field', function () {
    $this->actingAs(User::factory()->create(['password' => 'correct-horse-battery']));

    foreach (range(1, PasswordGuesses::PER_MINUTE) as $attempt) {
        $this->from(route('password.confirm'))->post(route('password.confirm.store'), ['password' => 'wrong-'.$attempt]);
    }

    $this->from(route('password.confirm'))->post(route('password.confirm.store'), ['password' => 'correct-horse-battery'])
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors('password');
    expect(session('errors')->first('password'))->toStartWith('Too many password attempts. Try again in')
        ->and(session('auth.step_up_confirmed_at'))->toBeNull();
});

test('a failed password confirmation is in the activity log, without the guess', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $this->actingAs($user)->postJson(route('password.confirm.store'), ['password' => 'wrong-guess'])->assertUnprocessable();

    $entry = ActivityLog::query()->where('event', 'auth.confirm_password_failed')->sole();
    expect($entry->user_id)->toBe($user->id)
        ->and(json_encode($entry->toArray()))->not->toContain('wrong-guess');
});

test('B1 correct confirmations never count: five in a minute do not lock the sixth', function () {
    $this->actingAs(User::factory()->create(['password' => 'correct-horse-battery']));

    foreach (range(1, PasswordGuesses::PER_MINUTE + 1) as $attempt) {
        $this->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])->assertCreated();
    }
});

test('after twenty wrong passwords in an hour the login is told to wait minutes, and sign-in still works', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->actingAs($user);

    foreach (range(1, PasswordGuesses::PER_HOUR - 1) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.intdiv($attempt, PasswordGuesses::PER_MINUTE)])
            ->postJson(route('password.confirm.store'), ['password' => 'wrong-'.$attempt])->assertUnprocessable();
    }

    // The twentieth uses the budget up and ends that session (#523); a new session is still refused.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson(route('password.confirm.store'), ['password' => 'wrong-last'])->assertTooManyRequests();
    $this->actingAs($user);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])
        ->assertTooManyRequests()
        ->assertJsonPath('message', fn (string $message): bool => (bool) preg_match('/^Too many wrong passwords\. Try again in \d+ minutes\.$/', $message));

    auth()->logout();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-horse-battery'])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

test('B6 account deletion counts wrong passwords against the same budget and keeps the account', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->actingAs($user);

    foreach (range(1, PasswordGuesses::PER_MINUTE) as $attempt) {
        $this->deleteJson(route('profile.destroy'), ['password' => 'wrong-'.$attempt])->assertUnprocessable();
    }

    $this->deleteJson(route('profile.destroy'), ['password' => 'correct-horse-battery'])->assertTooManyRequests();
    $this->from(route('profile.edit'))->delete(route('profile.destroy'), ['password' => 'correct-horse-battery'])
        ->assertSessionHasErrors('password');
    expect($user->fresh())->not->toBeNull();
});

test('wrong current passwords on a password change use up step-up too, so no route is a cheaper oracle', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->actingAs($user);

    foreach (range(1, PasswordGuesses::PER_MINUTE) as $attempt) {
        $this->from(route('security.edit'))->put(route('user-password.update'), [
            'current_password' => 'wrong-'.$attempt, 'password' => 'New-Password-123!', 'password_confirmation' => 'New-Password-123!',
        ])->assertSessionHasErrors('current_password');
    }

    $this->from(route('security.edit'))->put(route('user-password.update'), [
        'current_password' => 'correct-horse-battery', 'password' => 'New-Password-123!', 'password_confirmation' => 'New-Password-123!',
    ])->assertSessionHasErrors('current_password');
    expect(session('errors')->first('current_password'))->toStartWith('Too many password attempts.')
        ->and(Hash::check('correct-horse-battery', $user->fresh()->password))->toBeTrue();

    $this->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])->assertTooManyRequests();
});
