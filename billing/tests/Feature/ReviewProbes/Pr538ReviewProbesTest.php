<?php

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/*
 * Review board probes for #538 (step-up has its own key; password.confirm is step-up).
 */

test('R1 two-factor setup that crosses the 15-minute window asks for the password in place and finishes the step', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->actingAs($user);
    $inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => route('security.edit')];

    $this->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])->assertCreated();
    $this->get(route('security.edit'))->assertOk();

    $this->travel(14)->minutes();
    $this->post(route('two-factor.enable'), [], $inertia)->assertRedirect();

    $this->travel(2)->minutes();
    // useHttp() asks with Accept: application/json and gets password.recent's 423, which withStepUp() turns into the dialog.
    $this->getJson(route('two-factor.qr-code'))->assertStatus(423)->assertJsonPath('message', 'Password confirmation required.');

    // The confirm form gets the gate's error back on the same page; nothing sends it to the confirm page.
    $code = (new Google2FA)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
    $this->post(route('two-factor.confirm'), ['code' => $code], $inertia)
        ->assertRedirect(route('security.edit'))
        ->assertSessionHasErrors(RequireRecentPasswordConfirmation::ERROR_KEY);
    expect(session('url.intended'))->toBeNull()
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull();

    // The dialog confirms, and the same step is sent again.
    $this->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])->assertCreated();
    $this->getJson(route('two-factor.qr-code'))->assertOk();
    $this->post(route('two-factor.confirm'), ['code' => $code], $inertia)->assertSessionHasNoErrors();
    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

test('R1b a login without a password gets the set-a-password answer from password.confirm, over JSON and Inertia', function () {
    $user = User::factory()->create(['password' => null]);
    $this->actingAs($user);

    $this->getJson(route('passkey.registration-options'))->assertStatus(423)->assertJsonPath('code', RequireRecentPasswordConfirmation::NO_PASSWORD_CODE);
    $this->post(route('two-factor.enable'), [], ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => route('security.edit')])
        ->assertSessionHasErrors([RequireRecentPasswordConfirmation::REASON_KEY => RequireRecentPasswordConfirmation::NO_PASSWORD_CODE]);
});

test('R1c the passkey registration probe is answered by the middleware and never reaches the controller', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->actingAs($user);

    $this->getJson(route('passkey.registration-options'), [RequireRecentPasswordConfirmation::PROBE_HEADER => '1'])->assertStatus(423);
    expect(session('passkey.registration_options'))->toBeNull();

    $this->withSession([RequireRecentPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
        ->getJson(route('passkey.registration-options'), [RequireRecentPasswordConfirmation::PROBE_HEADER => '1'])->assertNoContent();
    expect(session('passkey.registration_options'))->toBeNull();
});

test('R2 a passkey confirmation (Laravel key fresh) opens neither the security page nor staff pages', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->get(route('security.edit'))->assertRedirect(route('password.confirm'));

    $staff = actingAsStaff();
    $this->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->get(route('admin.platform.staff.create'))->assertRedirect(route('password.confirm'));
});

test('R3 a login without a password reaches the set-password path from the security page', function () {
    $user = User::factory()->create(['password' => null]);

    $this->actingAs($user)->withSession(['auth.step_up_confirmed_at' => now()->unix()])
        ->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    $this->get(route('password.confirm'))->assertOk()->assertInertia(fn ($page) => $page->where('hasPassword', false));
    $this->postJson(route('password.set-link'))->assertSuccessful();
});

test('R4 the guess budget still guards the only writer of the step-up key', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->actingAs($user);

    foreach (range(1, 30) as $i) {
        $status = $this->postJson(route('password.confirm.store'), ['password' => "wrong-{$i}"])->status();
        if ($status !== 422) {
            break;
        }
    }
    expect($status)->not->toBe(422);
    $this->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery']);
    expect(session('auth.step_up_confirmed_at'))->toBeNull();
});
