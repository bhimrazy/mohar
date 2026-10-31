<?php

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * ADR 0018 §4: a login without a password never passes step-up and is told to set one. The staff factory always
 * sets a password and the User saving hook refuses a staff login with a null one (ADR 0018 §5), so the null is
 * written with the query builder, which bypasses the hook: this models a login that reached that state anyway.
 */
beforeEach(function () {
    Route::middleware(['web', 'auth', 'password.recent'])->group(function () {
        Route::post('/_step-up-probe', fn () => response('done'))->name('step-up-probe.store');
        Route::get('/_step-up-probe-file', fn () => response('file'))->name('step-up-probe.file');
    });
});

function passwordlessStaff(): User
{
    $user = User::factory()->staff(['*'])->create();
    DB::table('users')->where('id', $user->id)->update(['password' => null]);

    return $user->refresh();
}

it('tells a passwordless login to set a password on a JSON request, with no secret data', function () {
    $response = $this->actingAs(passwordlessStaff())->postJson('/_step-up-probe');

    $response->assertStatus(423)->assertJson(['code' => RequireRecentPasswordConfirmation::NO_PASSWORD_CODE]);
    expect(array_keys($response->json()))->toEqualCanonicalizing(['message', 'code']);
});

it('tells a passwordless login to set a password on an Inertia request', function () {
    $this->actingAs(passwordlessStaff())
        ->post('/_step-up-probe', [], ['X-Inertia' => 'true'])
        ->assertSessionHasErrors([
            'password_confirmation',
            'step_up_reason' => RequireRecentPasswordConfirmation::NO_PASSWORD_CODE,
        ]);
});

it('does not pass step-up for a passwordless login with a forged or fresh confirmation timestamp', function () {
    $user = passwordlessStaff();

    $this->actingAs($user)->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()])
        ->postJson('/_step-up-probe')->assertStatus(423);
    $this->actingAs($user)->withSession(['auth.step_up_confirmed_at' => PHP_INT_MAX])
        ->post('/_step-up-probe', [], ['X-Inertia' => 'true'])->assertSessionHasErrors('step_up_reason');
    $this->actingAs($user)->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()])
        ->get('/_step-up-probe-file', ['X-Step-Up-Check' => '1', 'Accept' => 'application/json'])
        ->assertStatus(423)->assertJson(['code' => 'password_not_set']);
});

it('cannot confirm a password with an empty one or a missing one', function () {
    $user = passwordlessStaff();

    foreach ([['password' => ''], []] as $body) {
        $this->actingAs($user)->postJson(route('password.confirm.store'), $body)->assertUnprocessable();
    }

    $this->actingAs($user)->postJson('/_step-up-probe')->assertStatus(423);
});

it('sends a passwordless login on a file download to the confirm page, which offers Set a password', function () {
    $user = passwordlessStaff();

    $this->actingAs($user)->get('/_step-up-probe-file')->assertRedirect(route('password.confirm'));

    $this->actingAs($user)->get(route('password.confirm'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/confirm-password')->where('hasPassword', false));
});

it('shares has_password in the auth props', function () {
    $this->actingAs(passwordlessStaff())->get(route('password.confirm'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.has_password', false)->missing('auth.user.password'));
});

it('leaves a login with a password unchanged', function () {
    $user = User::factory()->staff(['*'])->create();

    $this->actingAs($user)->postJson('/_step-up-probe')->assertStatus(423)->assertJsonMissingPath('code');
    $this->actingAs($user)->post('/_step-up-probe', [], ['X-Inertia' => 'true'])
        ->assertSessionHasErrors('password_confirmation')->assertSessionDoesntHaveErrors('step_up_reason');
    $this->actingAs($user)->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()])
        ->post('/_step-up-probe')->assertOk();
    $this->actingAs($user)->get(route('password.confirm'))
        ->assertInertia(fn (Assert $page) => $page->where('hasPassword', true)->where('auth.user.has_password', true));
});

/** A client login without a password: the real case, so no bypass of the staff hook is needed. */
function passwordlessClient(array $state = []): User
{
    return User::factory()->create(['password' => null, ...$state]);
}

function sentSetPasswordToken(User $user): string
{
    $token = null;
    Notification::assertSentTo($user, SetPasswordNotification::class, function (SetPasswordNotification $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    return $token;
}

it('lets only a login without a password ask for the set-password link, and says only "sent"', function () {
    Notification::fake();
    $user = passwordlessClient();

    $this->actingAs($user)->postJson(route('password.set-link'))->assertOk()->assertExactJson(['status' => 'sent']);
    Notification::assertSentTo($user, SetPasswordNotification::class);

    $withPassword = User::factory()->create();
    $this->actingAs($withPassword)->postJson(route('password.set-link'))->assertForbidden();
    Notification::assertNotSentTo($withPassword, SetPasswordNotification::class);

    auth()->logout();
    $this->postJson(route('password.set-link'))->assertUnauthorized();
});

it('sends a dedicated set-a-password mail whose link opens the signed-in page', function () {
    Notification::fake();
    $user = passwordlessClient();
    $this->actingAs($user)->postJson(route('password.set-link'))->assertOk();

    $mail = (new SetPasswordNotification(sentSetPasswordToken($user)))->toMail($user);

    expect($mail->subject)->toBe('Set a password for your account')
        ->and($mail->actionUrl)->toContain('/settings/password/set/')
        ->and($mail->actionUrl)->not->toContain('reset-password')
        ->and(implode(' ', $mail->introLines))->not->toContain('reset');
});

it('throttles the set-password link per login and across IPs, and sends one mail for five rapid requests', function () {
    Notification::fake();
    $user = passwordlessClient();

    foreach (range(1, 5) as $i) {
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])->postJson(route('password.set-link'))->assertOk();
    }

    $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->postJson(route('password.set-link'))->assertStatus(429);
    Notification::assertSentToTimes($user, SetPasswordNotification::class, 1);
});

it('refuses the set-password link to an unverified address, with a reason the UI can show', function () {
    Notification::fake();
    $user = passwordlessClient(['email_verified_at' => null]);

    $this->actingAs($user)->postJson(route('password.set-link'))->assertForbidden();
    Notification::assertNothingSent();
});

it('sets a password through the emailed link while signed in, without a current password, and step-up then works', function () {
    Notification::fake();
    $user = passwordlessStaff();
    $this->actingAs($user)->postJson(route('password.set-link'))->assertOk();
    $token = sentSetPasswordToken($user);

    $this->actingAs($user)->get(route('password.set', $token))
        ->assertInertia(fn (Assert $page) => $page->component('settings/set-password')->where('valid', true));

    $this->actingAs($user)->post(route('password.set.store', $token), ['password' => 'a-Strong-pass-123!', 'password_confirmation' => 'other'])
        ->assertSessionHasErrors('password');
    expect($user->refresh()->hasPassword())->toBeFalse();

    $this->actingAs($user)->post(route('password.set.store', $token), ['password' => 'a-Strong-pass-123!', 'password_confirmation' => 'a-Strong-pass-123!'])
        ->assertRedirect(route('profile.edit'));
    expect($user->refresh()->hasPassword())->toBeTrue();

    $this->actingAs($user)->postJson('/_step-up-probe')->assertStatus(423)->assertJsonMissingPath('code');
    $this->actingAs($user)->postJson(route('password.confirm.store'), ['password' => 'a-Strong-pass-123!'])->assertSuccessful();
    $this->actingAs($user)->post('/_step-up-probe')->assertOk();
});

it('refuses a link used twice, a link for another login, an expired link and a login that has a password', function () {
    Notification::fake();
    $user = passwordlessClient();
    $this->actingAs($user)->postJson(route('password.set-link'))->assertOk();
    $token = sentSetPasswordToken($user);
    $body = ['password' => 'a-Strong-pass-123!', 'password_confirmation' => 'a-Strong-pass-123!'];

    $other = passwordlessClient();
    $this->actingAs($other)->get(route('password.set', $token))->assertInertia(fn (Assert $page) => $page->where('valid', false));
    $this->actingAs($other)->post(route('password.set.store', $token), $body)->assertSessionHasErrors('token');
    expect($other->refresh()->hasPassword())->toBeFalse();

    $this->travel(61)->minutes();
    $this->actingAs($user)->get(route('password.set', $token))->assertInertia(fn (Assert $page) => $page->where('valid', false));
    $this->actingAs($user)->post(route('password.set.store', $token), $body)->assertSessionHasErrors('token');
    $this->travelBack();

    $fresh = Password::broker()->createToken($user);
    $this->actingAs($user)->post(route('password.set.store', $fresh), $body)->assertRedirect();
    $this->actingAs($user)->post(route('password.set.store', $fresh), $body)->assertSessionHasErrors('token');

    $again = Password::broker()->createToken($user->refresh());
    $this->actingAs($user)->get(route('password.set', $again))->assertInertia(fn (Assert $page) => $page->where('valid', false));
});

it('sends a signed-out visitor to sign in and back to the set-password page', function () {
    $user = passwordlessClient();
    $token = Password::broker()->createToken($user);

    $this->get(route('password.set', $token))->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe(route('password.set', $token));
});

it('leaves the guest reset flow unchanged for a login that has a password', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

it('says a login that already has a password has one, and an unverified one to verify first', function () {
    $this->actingAs(User::factory()->create())->postJson(route('password.set-link'))
        ->assertForbidden()->assertJson(['code' => 'password_already_set']);

    $this->actingAs(passwordlessClient(['email_verified_at' => null]))->postJson(route('password.set-link'))
        ->assertForbidden()->assertJsonMissingPath('code');
});
