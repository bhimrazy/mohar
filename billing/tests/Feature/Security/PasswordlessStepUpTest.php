<?php

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
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

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/_step-up-probe')->assertStatus(423);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => PHP_INT_MAX])
        ->post('/_step-up-probe', [], ['X-Inertia' => 'true'])->assertSessionHasErrors('step_up_reason');
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
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
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/_step-up-probe')->assertOk();
    $this->actingAs($user)->get(route('password.confirm'))
        ->assertInertia(fn (Assert $page) => $page->where('hasPassword', true)->where('auth.user.has_password', true));
});

it('lets only a login without a password ask for the set-password link, and says only "sent"', function () {
    Notification::fake();
    $user = passwordlessStaff();

    $this->actingAs($user)->postJson(route('password.set-link'))->assertOk()->assertExactJson(['status' => 'sent']);
    Notification::assertSentTo($user, ResetPassword::class);

    $withPassword = User::factory()->staff(['*'])->create();
    $this->actingAs($withPassword)->postJson(route('password.set-link'))->assertForbidden();
    Notification::assertNotSentTo($withPassword, ResetPassword::class);

    auth()->logout();
    $this->postJson(route('password.set-link'))->assertUnauthorized();
});

it('throttles the set-password link', function () {
    Notification::fake();
    RateLimiter::clear('set-link-user|1');
    $user = passwordlessStaff();

    foreach (range(1, 5) as $ignored) {
        $this->actingAs($user)->postJson(route('password.set-link'))->assertOk();
    }

    $this->actingAs($user)->postJson(route('password.set-link'))->assertStatus(429);
});

it('lets the emailed link set a password, after which step-up works with it', function () {
    Notification::fake();
    $user = passwordlessStaff();
    $this->actingAs($user)->postJson(route('password.set-link'))->assertOk();

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    auth()->logout();
    $this->flushSession();
    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-Strong-pass-123!',
        'password_confirmation' => 'a-Strong-pass-123!',
    ])->assertRedirect();

    expect($user->refresh()->hasPassword())->toBeTrue();

    $this->actingAs($user)->postJson('/_step-up-probe')->assertStatus(423)->assertJsonMissingPath('code');
    $this->actingAs($user)->postJson(route('password.confirm.store'), ['password' => 'a-Strong-pass-123!'])->assertSuccessful();
    $this->actingAs($user)->post('/_step-up-probe')->assertOk();
});
