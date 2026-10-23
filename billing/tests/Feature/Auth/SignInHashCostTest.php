<?php

use App\Actions\Fortify\RememberValidatedLogin;
use App\Actions\Fortify\SignInValidatedUser;
use App\Models\User;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Requests\LoginRequest;

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
    $key = Str::transliterate(Str::lower($user->email).'|127.0.0.1');
    RateLimiter::clear($key);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($hasher->checks)->toBe(1)->and(RateLimiter::attempts($key))->toBe(1);
});

test('the sign-in pipeline is Fortify\'s default except the one swapped step', function () {
    $pipes = function (): array {
        $controller = app(AuthenticatedSessionController::class);
        $pipeline = (new ReflectionMethod($controller, 'loginPipeline'))->invoke($controller, Mockery::mock(LoginRequest::class));

        return (new ReflectionProperty($pipeline, 'pipes'))->getValue($pipeline);
    };
    $ours = $pipes();
    $callback = Fortify::$authenticateThroughCallback;
    Fortify::$authenticateThroughCallback = null;
    config(['fortify.pipelines.login' => null]);

    try {
        $default = $pipes();
    } finally {
        Fortify::$authenticateThroughCallback = $callback;
    }

    // loginPipeline() drops steps switched off by config, so a new Fortify step that is off in tests is not compared.
    expect($default)->toContain(AttemptToAuthenticate::class)
        ->and(array_map(fn (string $step): string => $step === AttemptToAuthenticate::class ? SignInValidatedUser::class : $step, $default))->toBe($ours)
        ->and(app(RedirectsIfTwoFactorAuthenticatable::class))->toBeInstanceOf(RememberValidatedLogin::class);
});
