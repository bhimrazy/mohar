<?php

use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

/** Review probes for PR #384 round 2: the signed-in set-password page and its token. */
$body = ['password' => 'a-Strong-pass-123!', 'password_confirmation' => 'a-Strong-pass-123!'];

function setToken(User $user): string
{
    $token = null;
    Notification::assertSentTo($user, SetPasswordNotification::class, function (SetPasswordNotification $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    return $token;
}

it('refuses a token made for another login and does not burn it', function () use ($body) {
    $a = User::factory()->create(['password' => null]);
    $b = User::factory()->create(['password' => null]);
    $token = Password::broker()->createToken($a);

    $this->actingAs($b)->post(route('password.set.store', $token), $body)->assertSessionHasErrors('token');
    expect($b->fresh()->hasPassword())->toBeFalse()->and($a->fresh()->hasPassword())->toBeFalse();

    $this->actingAs($a)->get(route('password.set', $token))->assertInertia(fn (Assert $page) => $page->where('valid', true));
});

it('lets a guest reset token work on the set page and a set token work on the guest reset page', function () use ($body) {
    $a = User::factory()->create(['password' => null]);
    $this->actingAs($a)->post(route('password.set.store', Password::broker()->createToken($a)), $body)->assertRedirect(route('profile.edit'));

    Notification::fake();
    $b = User::factory()->create(['password' => null]);
    $this->actingAs($b)->postJson(route('password.set-link'))->assertOk();
    $setToken = setToken($b);

    auth()->logout();
    $this->flushSession();
    $this->post(route('password.update'), ['token' => $setToken, 'email' => $b->email, ...$body])->assertRedirect();
    expect($b->fresh()->hasPassword())->toBeTrue();
});

it('keeps the intended set-password URL through a password and two-factor sign-in', function () {
    $user = User::factory()->withTwoFactor()->create();
    $token = Password::broker()->createToken($user);

    $this->get(route('password.set', $token))->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect(session('url.intended'))->toBe(route('password.set', $token));
});

it('binds the intended URL to the request host, so a token cannot make an open redirect', function () {
    $this->get('/settings/password/set/'.urlencode('//evil.example/x'))->assertNotFound();
    $this->get('/settings/password/set/x?next=https://evil.example')->assertRedirect(route('login'));

    expect(parse_url((string) session('url.intended'), PHP_URL_HOST))->toBe(parse_url(route('login'), PHP_URL_HOST));
});

it('mints no new token for a second request within 60 seconds, so the first link still works', function () {
    Notification::fake();
    $user = User::factory()->create(['password' => null]);

    $this->actingAs($user)->postJson(route('password.set-link'))->assertOk();
    $first = setToken($user);
    $this->actingAs($user)->postJson(route('password.set-link'))->assertOk();

    Notification::assertSentToTimes($user, SetPasswordNotification::class, 1);
    $this->actingAs($user)->get(route('password.set', $first))->assertInertia(fn (Assert $page) => $page->where('valid', true));
});

it('keeps the token out of the Referer header', function () {
    $user = User::factory()->create(['password' => null]);
    $token = Password::broker()->createToken($user);

    $this->actingAs($user)->get(route('password.set', $token))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
});
