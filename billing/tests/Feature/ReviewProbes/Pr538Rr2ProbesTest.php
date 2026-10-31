<?php

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\User;

/*
 * Re-review 2 probes for #538: the returningToRefusedPage() pass-through with an expired window.
 */

/** @return array{0: User, 1: string} the user and the Inertia version */
function rr2Confirmed(): array
{
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => encrypt(json_encode(['old-1', 'old-2'])), 'two_factor_confirmed_at' => now()])->save();
    test()->actingAs($user)->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])->assertCreated();
    $version = (string) (test()->get(route('security.edit'))->assertOk()->viewData('page')['version'] ?? '');

    return [$user, $version];
}

test('Q1 a JSON endpoint behind password.confirm, loaded once as a non-JSON GET in the window, stays readable after it', function () {
    [$user, $version] = rr2Confirmed();
    $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'Accept' => 'text/html'];

    // In the window: a plain (non-JSON) Inertia GET of the recovery codes records them as the "page".
    $this->withHeaders($inertia)->get(route('two-factor.recovery-codes'))->assertOk();
    expect(session(RequireRecentPasswordConfirmation::PAGE_KEY))->toBe(route('two-factor.recovery-codes'));

    $this->travel(2)->hours();
    // The owner regenerates the codes on another device.
    $user->forceFill(['two_factor_recovery_codes' => encrypt(json_encode(['new-1', 'new-2']))])->save();

    foreach ([1, 2, 3] as $round) {
        // Any refused mutation, with the Referer chosen by the caller, flashes the URL...
        $this->withHeaders([...$inertia, 'Referer' => route('two-factor.recovery-codes')])->post(route('two-factor.regenerate-recovery-codes'))
            ->assertSessionHasErrors(RequireRecentPasswordConfirmation::ERROR_KEY);
        // ...and the next Inertia GET returns the current codes without a recent confirmation.
        $response = $this->withHeaders($inertia)->get(route('two-factor.recovery-codes'));
        $response->assertOk();
        expect($response->json())->toBe(['new-1', 'new-2']);
    }
    $this->withHeaders($inertia)->get(route('two-factor.secret-key'))->assertRedirect(route('password.confirm'));
});

test('Q2 the security page is shown once per refusal, but refusals are free, so it is shown as often as asked', function () {
    [, $version] = rr2Confirmed();
    $this->travel(16)->minutes();
    $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => route('security.edit')];

    foreach ([1, 2, 3] as $round) {
        $this->withHeaders($inertia)->post(route('two-factor.regenerate-recovery-codes'))->assertRedirect(route('security.edit'));
        $this->withHeaders($inertia)->get(route('security.edit'))->assertOk()->assertJsonPath('component', 'settings/security');
        // The flash is spent: a second GET goes to the confirm page.
        $this->withHeaders($inertia)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    }
});

test('Q3 a version-mismatch GET reflashes the refusal, so the flash outlives its first use', function () {
    [, $version] = rr2Confirmed();
    $this->travel(16)->minutes();
    $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => route('security.edit')];

    $this->withHeaders($inertia)->post(route('two-factor.enable'))->assertRedirect(route('security.edit'));
    $this->withHeaders([...$inertia, 'X-Inertia-Version' => 'stale'])->get(route('security.edit'))->assertStatus(409);
    $this->withHeaders([...$inertia, 'X-Inertia-Version' => 'stale'])->get(route('security.edit'))->assertStatus(409);
    $this->withHeaders($inertia)->get(route('security.edit'))->assertOk()->assertJsonPath('component', 'settings/security');
});

test('Q4 query variants, other pages, partial reloads and prefetch do not widen the pass-through', function () {
    [$user, $version] = rr2Confirmed();
    $this->travel(16)->minutes();
    $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'];
    $refuse = fn (string $from) => $this->withHeaders([...$inertia, 'Referer' => $from])->post(route('two-factor.enable'));

    $refuse(route('security.edit').'?x=1');
    $this->withHeaders($inertia)->get(route('security.edit').'?x=1')->assertRedirect(route('password.confirm'));

    $refuse(route('two-factor.qr-code'));
    $this->withHeaders([...$inertia, 'Accept' => 'text/html'])->get(route('two-factor.qr-code'))->assertRedirect(route('password.confirm'));

    $refuse(route('security.edit'));
    $partial = $this->withHeaders([...$inertia, 'X-Inertia-Partial-Component' => 'settings/security', 'X-Inertia-Partial-Data' => 'passkeys,twoFactorEnabled'])->get(route('security.edit'));
    $partial->assertOk();
    expect(array_keys($partial->json('props')))->not->toContain('two_factor_secret', 'recoveryCodes', 'qrCode');

    $refuse(route('security.edit'));
    $this->withHeaders([...$inertia, 'Purpose' => 'prefetch', 'Sec-Purpose' => 'prefetch'])->get(route('security.edit'))->assertOk();
    $this->withHeaders($inertia)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
});

test('Q5 PAGE_KEY: logout clears it, sign-in keeps it, impersonation blocks the pages', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->actingAs($user)->withSession([RequireRecentPasswordConfirmation::PAGE_KEY => route('security.edit')])->post(route('logout'));
    expect(session(RequireRecentPasswordConfirmation::PAGE_KEY))->toBeNull();

    auth()->logout();
    $this->withSession([RequireRecentPasswordConfirmation::PAGE_KEY => route('security.edit')])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-horse-battery']);
    $this->assertAuthenticatedAs($user);
    expect(session(RequireRecentPasswordConfirmation::PAGE_KEY))->toBe(route('security.edit'));
});
