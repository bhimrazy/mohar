<?php

use App\Actions\Fortify\RememberValidatedLogin;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

/** Review probes for PR #374: the single-verification sign-in pipeline. */
function pr374Hasher(): object
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

test('a two-factor user is sent to the challenge, not signed in, after one verification', function () {
    $user = User::factory()->withTwoFactor()->create();
    $hasher = pr374Hasher();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => '1'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect($hasher->checks)->toBe(1)
        ->and(session('login.id'))->toBe($user->id)
        ->and(session('login.remember'))->toBeTrue();
});

test('remember me still sets the recaller cookie and a remember token', function () {
    $user = User::factory()->create(['remember_token' => null]);

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => '1']);

    $this->assertAuthenticatedAs($user);
    $response->assertCookie(auth()->guard('web')->getRecallerName());
    expect($user->fresh()->remember_token)->not->toBeNull();
});

test('without remember me no recaller cookie is queued', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
    $response->assertCookieMissing(auth()->guard('web')->getRecallerName());
});

test('a weaker stored hash is rehashed on sign-in', function () {
    $user = User::factory()->create();
    $user->forceFill(['password' => (new BcryptHasher(['rounds' => 4]))->make('password')])->saveQuietly();
    config(['hashing.bcrypt.rounds' => 5]);
    Hash::forgetDrivers();
    $before = $user->fresh()->password;

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->password)->not->toBe($before)
        ->and(password_get_info($user->fresh()->password)['options']['cost'])->toBe(5);
});

test('the session id is regenerated and Login fires once, Failed never, on success', function () {
    $user = User::factory()->create();
    Event::fake([Login::class, Failed::class]);
    $this->startSession();
    $before = session()->getId();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect(session()->getId())->not->toBe($before);
    Event::assertDispatchedTimes(Login::class, 1);
    Event::assertNotDispatched(Failed::class);
});

test('wrong passwords still lock the email after the configured attempts', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'nope'])->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertGuest();
});

test('a disabled login cannot use the app after signing in', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->get(route('dashboard'))->assertStatus(401);
    $this->assertGuest();
});

test('the validated user cannot be injected from input, and does not survive into the next request', function () {
    $victim = User::factory()->create();
    $attacker = User::factory()->create();

    $this->post(route('login.store'), ['email' => $attacker->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($attacker);
    $this->post(route('logout'));
    $this->assertGuest();

    $this->post(route('login.store'), [
        'email' => $victim->email,
        'password' => 'wrong',
        RememberValidatedLogin::ATTRIBUTE => $victim->id,
        'login' => ['validated_user' => $victim->id],
    ])->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('unknown email, wrong password and success all take at least the 200 ms time box', function () {
    $user = User::factory()->create();
    $time = function (array $data): float {
        $start = hrtime(true);
        $this->post(route('login.store'), $data);
        auth()->guard('web')->logout();

        return (hrtime(true) - $start) / 1e6;
    };

    $unknown = $time(['email' => 'nobody@example.test', 'password' => 'password']);
    $wrong = $time(['email' => $user->email, 'password' => 'wrong']);
    $ok = $time(['email' => $user->email, 'password' => 'password']);
    fwrite(STDERR, sprintf("timing unknown=%.0fms wrong=%.0fms ok=%.0fms\n", $unknown, $wrong, $ok));

    expect($unknown)->toBeGreaterThanOrEqual(200)
        ->and($wrong)->toBeGreaterThanOrEqual(200)
        ->and($ok)->toBeGreaterThanOrEqual(200);
});
