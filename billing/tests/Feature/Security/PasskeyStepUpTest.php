<?php

use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Notifications\LoginEmailNotice;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\TemplateCatalog;
use Illuminate\Support\Facades\Notification;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;

/*
 * Security review 2026-10-31, M1: only a password confirmation is step-up (ADR 0018 §4), and Fortify's
 * `password.confirm` routes (passkey registration and removal, every two-factor route) use the step-up window.
 */

test('a password confirmation through Fortify opens passkey registration and two-factor routes', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->actingAs($user);

    $this->getJson(route('passkey.registration-options'))->assertStatus(423);
    $this->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])->assertCreated();

    expect(session('auth.step_up_confirmed_at'))->toBe(now()->getTimestamp());
    $this->getJson(route('passkey.registration-options'))->assertOk();
    $this->postJson(route('two-factor.enable'))->assertOk();
});

test('a wrong password records no step-up', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $this->actingAs($user)->postJson(route('password.confirm.store'), ['password' => 'wrong-guess'])->assertUnprocessable();

    expect(session('auth.step_up_confirmed_at'))->toBeNull();
});

test('the timestamp a passkey confirmation sets opens no password.confirm route', function (string $method, string $route) {
    $user = User::factory()->create(['password' => 'correct-horse-battery', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
    $passkey = $user->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'cred-1', 'credential' => []]);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->json($method, route($route, $route === 'passkey.destroy' ? $passkey : []))
        ->assertStatus(423);
})->with([
    ['GET', 'passkey.registration-options'],
    ['POST', 'passkey.store'],
    ['DELETE', 'passkey.destroy'],
    ['POST', 'two-factor.enable'],
    ['POST', 'two-factor.confirm'],
    ['DELETE', 'two-factor.disable'],
    ['GET', 'two-factor.qr-code'],
    ['GET', 'two-factor.secret-key'],
    ['GET', 'two-factor.recovery-codes'],
    ['POST', 'two-factor.regenerate-recovery-codes'],
]);

test('the security settings page asks again after the step-up window', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $this->actingAs($user)->withSession(['auth.step_up_confirmed_at' => now()->subMinutes(16)->unix()])
        ->get(route('security.edit'))
        ->assertRedirect(route('password.confirm'));
});

test('an added passkey is logged and mailed to the login through its template', function (bool $staff, string $key) {
    app(TemplateCatalog::class)->install('en');
    $user = User::factory()->create(['email' => 'asha@example.com.np', 'name' => 'Asha Gurung', 'is_staff' => $staff]);
    $passkey = $user->passkeys()->create(['name' => 'Call +977 980000 0000', 'credential_id' => 'cred-1', 'credential' => []]);

    PasskeyRegistered::dispatch($user, $passkey);

    $mail = EmailMessage::query()->where('template_key', $key)->sole();
    expect($mail->to)->toBe(['asha@example.com.np'])
        ->and($mail->body_text)->toContain('A passkey was added')->not->toContain('Call +977')
        ->and(ActivityLog::query()->where('event', 'auth.passkey_added')->sole()->properties)->toMatchArray(['passkey_id' => $passkey->id]);
})->with([
    'client login' => [false, 'auth.passkey_added'],
    'staff login' => [true, 'staff.passkey_added'],
]);

test('without the template system the added passkey notice is a plain mail', function () {
    Notification::fake();
    $user = User::factory()->create();
    $passkey = new Passkey(['name' => 'Laptop', 'credential_id' => 'cred-1', 'credential' => []]);
    $passkey->id = 7;

    PasskeyRegistered::dispatch($user, $passkey);

    Notification::assertSentTo($user, LoginEmailNotice::class, fn (LoginEmailNotice $notice): bool => $notice->subject === __('A passkey was added to your account'));
});
