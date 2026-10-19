<?php

use App\Contracts\Auth\SignInGate;
use App\Contracts\Auth\SignInMethodCounter;
use App\Data\Auth\SignInMethod;
use App\Data\Core\NewClientAccount;
use App\Events\Core\ClientRegistered;
use App\Models\User;
use App\Modules\Core\Actions\RegisterClientAccount;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Support\Auth\CompleteSignIn;
use App\Support\Auth\SignInMethods;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Finder\Finder;

/**
 * The sign-in kernel (ADR 0018): logins without a password, the one step-up, the sign-in gate and CompleteSignIn's
 * handoff to Fortify's two-factor challenge, the methods registry and counter, client registration and the sign-up
 * switch.
 */
beforeEach(function () {
    Currency::factory()->create(['code' => 'USD', 'is_default' => true]);
});

function passwordlessClient(array $attributes = []): User
{
    [$user] = app(RegisterClientAccount::class)->handle(new NewClientAccount('Ann', 'Lee', $attributes['email'] ?? 'ann@example.com', emailVerifiedAt: now()));

    return $user;
}

/**
 * Signs the user in through CompleteSignIn from a throwaway route, as a sign-in method's callback would.
 */
function completeSignInFor(User $user): TestResponse
{
    Route::middleware('web')->get('/_test/complete-sign-in/{user}', fn (Request $request, User $user) => CompleteSignIn::for($request, $user, 'test'));

    return test()->get('/_test/complete-sign-in/'.$user->id);
}

test('a login without a password has none to match, and staff logins always keep one', function () {
    $client = passwordlessClient();

    expect($client->hasPassword())->toBeFalse()
        ->and($client->getRawOriginal('password'))->toBeNull()
        ->and(User::factory()->create()->hasPassword())->toBeTrue();

    $this->post(route('login.store'), ['email' => $client->email, 'password' => ''])->assertSessionHasErrors();
    $this->assertGuest();

    expect(fn () => $client->forceFill(['is_staff' => true])->save())->toThrow(DomainException::class)
        ->and($client->refresh()->is_staff)->toBeFalse();
});

test('staff invitations and staff creation refuse an address a passwordless login uses, with the reason', function () {
    $client = passwordlessClient(['email' => 'taken@example.com']);
    actingAsStaff();
    confirmPassword();

    $this->post(route('admin.platform.staff.store'), ['mode' => 'invite', 'email' => 'taken@example.com', 'role_id' => User::query()->where('is_staff', true)->firstOrFail()->role_id])
        ->assertSessionHasErrors(['email' => 'A login with this email address already exists.']);

    expect($client->refresh()->is_staff)->toBeFalse();
});

test('step-up asks a passwordless login to set a password instead of confirming one', function () {
    Route::middleware(['web', 'auth', 'password.recent'])->post('/_test/step-up', fn () => 'ok');

    $this->actingAs(passwordlessClient());
    $this->post('/_test/step-up')->assertSessionHasErrors(['password_confirmation' => 'Set a password to continue.', 'password_setup']);
    $this->postJson('/_test/step-up')->assertStatus(423)->assertSee('Set a password to continue.');

    $this->actingAs(User::factory()->create());
    $this->post('/_test/step-up')->assertSessionHasErrors(['password_confirmation' => 'Confirm your password to continue.'])->assertSessionDoesntHaveErrors('password_setup');

    confirmPassword();
    $this->post('/_test/step-up')->assertOk();
});

test('the step-up window is the one auth.password_timeout', function () {
    config(['auth.password_timeout' => 60]);
    Route::middleware(['web', 'auth', 'password.recent'])->post('/_test/step-up', fn () => 'ok');
    $this->actingAs(User::factory()->create());

    $this->withSession(['auth.password_confirmed_at' => time() - 61])->post('/_test/step-up')->assertSessionHasErrors('password_confirmation');
    $this->withSession(['auth.password_confirmed_at' => time() - 30])->post('/_test/step-up')->assertOk();
});

test('CompleteSignIn signs in a login without 2FA, regenerates the session and ignores an off-site intended URL', function () {
    $user = User::factory()->create();
    $this->withSession(['url.intended' => 'https://evil.example/steal']);
    $before = session()->getId();

    completeSignInFor($user)->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($before);
});

test('CompleteSignIn hands a 2FA login to Fortify\'s challenge, where only the right code signs in', function () {
    $user = User::factory()->withTwoFactor()->create();

    completeSignInFor($user)->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
    expect(session(CompleteSignIn::CHALLENGE_USER))->toBe($user->id)->and(session(CompleteSignIn::CHALLENGE_REMEMBER))->toBeFalse();

    // Pins Fortify's contract: its challenge reads the keys CompleteSignIn writes.
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'wrong-code']);
    $this->assertGuest();

    completeSignInFor($user);
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1']);
    $this->assertAuthenticatedAs($user);
});

test('CompleteSignIn applies the password login\'s refusals, and method failures count towards the lockout', function () {
    $disabled = User::factory()->create(['email' => 'off@example.com']);
    $disabled->forceFill(['is_active' => false])->save();

    completeSignInFor($disabled)->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'Your account has been disabled.']);
    $this->assertGuest();

    $user = User::factory()->create();
    $gate = app(SignInGate::class);

    foreach (range(1, (int) app(Settings::class)->get('security.login_max_attempts', 5)) as $attempt) {
        $gate->recordFailure($user, '127.0.0.1');
    }

    completeSignInFor($user)->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('a client login whose every account is closed is refused', function () {
    $user = passwordlessClient();
    Client::query()->whereKey($user->clients()->firstOrFail()->id)->update(['status' => 'closed']);

    completeSignInFor($user->refresh())->assertSessionHasErrors(['email' => 'This account is closed. Contact us to reopen it.']);
    $this->assertGuest();
});

test('registered sign-in methods reach the login view only while they are available', function () {
    $enabled = true;
    app(SignInMethods::class)->register('acme', function () use (&$enabled): ?SignInMethod {
        return $enabled ? new SignInMethod('acme', 'Continue with Acme', 'https://billing.test/auth/acme') : null;
    });

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('signInMethods.0.key', 'acme')->where('signInMethods.0.staff', false));

    $enabled = false;
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('signInMethods', []));
});

test('the sign-in method counter counts a password, and modules extend it with their own', function () {
    $counter = app(SignInMethodCounter::class);

    expect($counter->count(User::factory()->create()))->toBe(1)
        ->and($counter->count(passwordlessClient()))->toBe(0);

    app()->extend(SignInMethodCounter::class, fn (SignInMethodCounter $inner) => new class($inner) implements SignInMethodCounter
    {
        public function __construct(private SignInMethodCounter $inner) {}

        public function count(User $user): int
        {
            return $this->inner->count($user) + 1;
        }
    });

    expect(app(SignInMethodCounter::class)->count(User::factory()->create(['email' => 'two@example.com'])))->toBe(2);
});

test('RegisterClientAccount opens the login, client and owner link, and announces it only after commit', function () {
    Event::fake([ClientRegistered::class, Registered::class]);

    DB::transaction(function () {
        app(RegisterClientAccount::class)->handle(new NewClientAccount('Ann', 'Lee', 'ANN@Example.com', 'Secret-Password-1', currency: 'USD', companyName: 'Lee Co'));
        Event::assertNotDispatched(ClientRegistered::class);
    });

    $user = User::query()->where('email', 'ann@example.com')->sole();
    $client = $user->clients()->sole();
    expect($user->hasPassword())->toBeTrue()
        ->and($client)->company_name->toBe('Lee Co')->currency->toBe('USD')
        ->and($client->pivot->is_owner)->toBeTruthy();
    Event::assertDispatched(ClientRegistered::class, fn (ClientRegistered $event) => $event->clientId === $client->id && $event->userId === $user->id);
    Event::assertDispatched(Registered::class);
});

test('closed sign-up shows the closed page, refuses POST /register, and hides the sign-up links', function () {
    app(Settings::class)->set('security.client_signup_enabled', false);

    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->component('auth/register')->where('signupOpen', false));
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('auth.signup_open', false));

    $this->post(route('register.store'), ['name' => 'Bo Ray', 'email' => 'bo@example.com', 'password' => 'Secret-Password-1', 'password_confirmation' => 'Secret-Password-1'])
        ->assertSessionHasErrors(['email' => 'Sign-up is closed. Contact us if you need an account.']);

    expect(User::query()->where('email', 'bo@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

test('the nullable password migration keeps the lower(email) index', function () {
    $indexes = match (DB::connection()->getDriverName()) {
        'sqlite' => DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', 'users')->pluck('name')->all(),
        'pgsql' => DB::table('pg_indexes')->where('tablename', 'users')->pluck('indexname')->all(),
        default => ['users_email_lower_index'],
    };

    expect($indexes)->toContain('users_email_lower_index')
        ->and(passwordlessClient(['email' => 'nopass@example.com'])->getRawOriginal('password'))->toBeNull();
});

test('only CompleteSignIn writes Fortify\'s challenge keys outside Fortify', function () {
    $writers = collect(Finder::create()->files()->in(app_path())->name('*.php'))
        ->filter(fn (SplFileInfo $file): bool => preg_match("/['\"]login\\.(id|remember)['\"]/", (string) file_get_contents($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => str_replace(app_path().'/', '', $file->getPathname()))
        ->values()->all();

    expect($writers)->toBe(['Support/Auth/CompleteSignIn.php']);
});
