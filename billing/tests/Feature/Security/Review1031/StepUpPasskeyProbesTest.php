<?php

/*
 * Security round 2026-10-31, step-up track (M1). ADR 0018 §4: only Fortify's confirm-password sets
 * auth.password_confirmed_at, and a passkey may count only through an amendment. Fortify's passkey feature registers
 * POST /passkeys/confirm, which calls Session::passwordConfirmed() and so satisfies password.recent. Registering a
 * passkey (and turning two-factor off) is gated by Laravel's password.confirm, whose window is auth.password_timeout
 * (3 hours), not auth.step_up_seconds (15 minutes). A session taken up to 3 hours after the owner's last confirmation
 * can register its own passkey and then pass every step-up gate (email change, secrets, roles) without the password
 * and without the #522 guess budget.
 */

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

function b64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** A structurally valid assertion; its signature is never checked because VerifyPasskey is replaced. */
function fakeAssertion(): array
{
    $id = b64url(random_bytes(16));

    return [
        'id' => $id,
        'rawId' => $id,
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => b64url((string) json_encode(['type' => 'webauthn.get', 'challenge' => b64url(random_bytes(32)), 'origin' => config('app.url')])),
            'authenticatorData' => b64url(hash('sha256', 'localhost', true)."\x05\x00\x00\x00\x01"),
            'signature' => b64url('sig'),
        ],
    ];
}

beforeEach(function () {
    Route::middleware(['web', 'auth', 'password.recent'])->post('/_probe/step-up', fn () => response()->noContent())->name('probe.step-up');
    Route::getRoutes()->refreshNameLookups();
});

test('S1 registering a passkey needs the step-up window, not the three-hour password.confirm window', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    // The owner confirmed an hour ago: outside step-up (15 min), inside password_timeout (3 h).
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->subHour()->unix()])
        ->getJson(route('passkey.registration-options'))
        ->assertStatus(423);
});

test('S2 a passkey confirmation does not pass password.recent unless ADR 0018 is amended to allow it', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    app()->bind(VerifyPasskey::class, fn () => new class extends VerifyPasskey
    {
        public function __invoke(PublicKeyCredential $credential, PublicKeyCredentialRequestOptions $options, ?PasskeyUser $user = null): Passkey
        {
            return new Passkey;
        }
    });

    $this->actingAs($user)->getJson(route('passkey.confirm-options'))->assertOk();
    $this->postJson(route('passkey.confirm'), ['credential' => fakeAssertion()])->assertSuccessful();

    $this->postJson(route('probe.step-up'))->assertStatus(423);
});

test('S3 turning two-factor authentication off needs the step-up window', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->subHour()->unix()])
        ->deleteJson(route('two-factor.disable'))
        ->assertStatus(423);
    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

test('S4 completing the two-factor challenge does not count as a password confirmation (holds)', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-horse-battery']);
    $this->assertAuthenticatedAs($user);

    expect(session('auth.password_confirmed_at'))->toBeNull();
    $this->postJson(route('probe.step-up'))->assertStatus(423);
});
