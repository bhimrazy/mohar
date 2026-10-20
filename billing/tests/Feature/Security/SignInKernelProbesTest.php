<?php

use App\Contracts\Auth\SignInGate;
use App\Data\Core\NewClientAccount;
use App\Models\User;
use App\Modules\Core\Actions\RegisterClientAccount;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Platform\Models\StaffInvitation;
use App\Modules\Platform\Services\PlatformSignInGate;
use App\Support\Auth\CompleteSignIn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * Security review of #112 (sign-in kernel, ADR 0018). Tests without a prefix pin defences that hold at 7b4b4d7;
 * tests prefixed "M:" or "L:" reproduce the review's findings and fail until they are fixed.
 */
beforeEach(fn () => Currency::factory()->create(['code' => 'USD', 'is_default' => true]));

function probePasswordless(string $email = 'nopass@example.com'): User
{
    [$user] = app(RegisterClientAccount::class)->handle(new NewClientAccount('No', 'Pass', $email, emailVerifiedAt: now()));

    return $user;
}

function probeCompleteSignIn(User $user): TestResponse
{
    Route::middleware('web')->get('/_probe/complete-sign-in/{user}', fn (Request $request, User $user) => CompleteSignIn::for($request, $user, 'probe'));

    return test()->get('/_probe/complete-sign-in/'.$user->id);
}

test('a passwordless login matches no password at login, in confirm-password, or in the current_password rule', function () {
    $user = probePasswordless();

    foreach (['', ' ', 'null', 'password', '0'] as $guess) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => $guess]);
        $this->assertGuest();
    }

    expect(Auth::validate(['email' => $user->email, 'password' => 'anything']))->toBeFalse()
        ->and(Auth::attempt(['email' => $user->email, 'password' => '']))->toBeFalse();

    $this->actingAs($user);
    $this->post(route('password.confirm.store'), ['password' => 'anything'])->assertSessionHasErrors();
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();

    $this->put(route('user-password.update'), ['current_password' => '', 'password' => 'New-Password-123!', 'password_confirmation' => 'New-Password-123!'])
        ->assertSessionHasErrors('current_password');
    expect($user->refresh()->getRawOriginal('password'))->toBeNull();
});

test('no path makes a passwordless login staff: the save hook, clearing a staff password, and a later staff invitation', function () {
    $staff = User::factory()->staff()->create();
    expect(fn () => $staff->forceFill(['password' => null])->save())->toThrow(DomainException::class);

    // Invited first, then the address signs up without a password (a sign-in provider): accepting is refused.
    $invitation = StaffInvitation::factory()->withToken('probe-token')->create(['email' => 'later@example.com']);
    $client = probePasswordless('later@example.com');

    $this->post(route('platform.invitations.accept', 'probe-token'), ['name' => 'Later', 'password' => 'Secret-Password-1', 'password_confirmation' => 'Secret-Password-1'])
        ->assertSessionHasErrors('token');

    expect($client->refresh()->is_staff)->toBeFalse()->and($invitation->refresh()->accepted_at)->toBeNull();
    $this->assertGuest();
});

test('a normal install runs Platform\'s sign-in gate, never Core\'s allow-all default', function () {
    expect(app(SignInGate::class))->toBeInstanceOf(PlatformSignInGate::class);
});

test('failed passwords lock out CompleteSignIn, and method failures lock out the password, case-insensitively', function () {
    $victim = User::factory()->create(['email' => 'victim@example.com']);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => 'VICTIM@Example.com', 'password' => 'wrong-'.$attempt]);
    }

    $this->travel(2)->minutes(); // past Fortify's per-minute limiter
    probeCompleteSignIn($victim)->assertSessionHasErrors('email');
    $this->assertGuest();

    $other = User::factory()->create(['email' => 'other@example.com']);

    foreach (range(1, 5) as $attempt) {
        app(SignInGate::class)->recordFailure($other, '127.0.0.1');
    }

    $this->post(route('login.store'), ['email' => 'other@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('the gate is checked before the 2FA handoff, and the handoff never carries "remember me"', function () {
    $disabled = User::factory()->withTwoFactor()->create();
    $disabled->forceFill(['is_active' => false])->save();

    probeCompleteSignIn($disabled)->assertRedirect(route('login'));
    expect(session()->has(CompleteSignIn::CHALLENGE_USER))->toBeFalse();
    $this->assertGuest();

    $user = User::factory()->withTwoFactor()->create();
    $this->withSession([CompleteSignIn::CHALLENGE_REMEMBER => true]);
    probeCompleteSignIn($user)->assertRedirect(route('two-factor.login'));
    expect(session(CompleteSignIn::CHALLENGE_REMEMBER))->toBeFalse();
    $this->assertGuest();
});

test('CompleteSignIn follows an intended URL only on APP_URL', function (string $intended, bool $kept) {
    config(['app.url' => 'https://billing.test']);
    $this->withSession(['url.intended' => $intended]);

    probeCompleteSignIn(User::factory()->create())->assertRedirect($kept ? $intended : route('dashboard'));
})->with([
    'same site' => ['https://billing.test/client/invoices', true],
    'look-alike host' => ['https://billing.test.evil.example/x', false],
    'userinfo trick' => ['https://billing.test@evil.example/', false],
    'protocol-relative' => ['//evil.example/x', false],
    'other scheme' => ['http://billing.test/x', false],
]);

test('/register refuses an address that differs from an existing login only by case', function () {
    User::factory()->create(['email' => 'ann@example.com']);

    $this->post(route('register.store'), ['name' => 'Ann Lee', 'email' => 'Ann@Example.com', 'password' => 'Secret-Password-1', 'password_confirmation' => 'Secret-Password-1'])
        ->assertSessionHasErrors('email');

    expect(User::query()->whereRaw('lower(email) = ?', ['ann@example.com'])->count())->toBe(1);
});

test('M: only confirm-password writes the step-up timestamp (ADR 0018 §4)', function () {
    $writers = collect(Route::getRoutes()->getRoutes())
        ->filter(function ($route): bool {
            $class = $route->getControllerClass();

            if ($class === null || ! class_exists($class) || in_array('GET', $route->methods(), true)) {
                return false;
            }

            $source = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());

            return preg_match("/passwordConfirmed\\(\\)|['\"]auth\\.password_confirmed_at['\"]\\s*,\\s*(Date::now|now|time)/", $source) === 1;
        })
        ->map(fn ($route): string => (string) $route->getName())
        ->values()->all();

    expect($writers)->toBe(['password.confirm.store']);
});

test('M: the password login applies the same refusals as SignInGate (closed client, disabled login)', function () {
    $closed = User::factory()->create(['email' => 'closed@example.com']);
    $client = Client::factory()->create(['status' => 'closed']);
    $client->users()->attach($closed, ['is_owner' => true]);

    $this->post(route('login.store'), ['email' => 'closed@example.com', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'This account is closed. Contact us to reopen it.']);
    $this->assertGuest();

    $disabled = User::factory()->create(['email' => 'off@example.com']);
    $disabled->forceFill(['is_active' => false])->save();

    $this->post(route('login.store'), ['email' => 'off@example.com', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Your account has been disabled.']);
    $this->assertGuest();
});

test('L: with APP_URL empty, a protocol-relative intended URL is not followed', function () {
    config(['app.url' => '']);
    $this->withSession(['url.intended' => '//evil.example/x']);

    probeCompleteSignIn(User::factory()->create())->assertRedirect(route('dashboard'));
});
