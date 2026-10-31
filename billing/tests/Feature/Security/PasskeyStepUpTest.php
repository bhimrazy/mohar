<?php

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Notifications\LoginEmailNotice;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\TemplateCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use Symfony\Component\Finder\SplFileInfo;

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
        ->and($mail->body_text)->toContain('A passkey was added')->toContain(route('security.edit'))->toContain('Added')->not->toContain('Call +977')
        ->and($staff ? $mail->body_text : 'Hi Asha')->toContain('Hi Asha')
        ->and($staff ? $mail->body_text : '')->not->toContain(Route::has('client.support.tickets.index') ? route('client.support.tickets.index') : 'client-support')
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

/**
 * Why a frontend source reaches Fortify's passkey confirmation or confirmed-password status endpoint, if it does.
 * Both are registered by Fortify with no option to drop them: the first sets only Laravel's key, the second reports
 * it, and neither is step-up (#538). The two route modules that expose them may be imported only by name, without
 * those names: a default, namespace or dynamic import would reach them as `passkey.confirm()`.
 *
 * @return list<string>
 */
function confirmEndpointUses(string $source): array
{
    $from = '\s*from\s*[\'"](?:@/|(?:\.\./)+)routes/';
    $module = '(?:/index)?(?:\.ts)?[\'"]';
    $patterns = [
        'vendor controller or URL' => '#PasskeyConfirmationController|ConfirmedPasswordStatusController|passkeys/confirm|confirmed-password-status#',
        'named confirm import' => '#import\s+(?:type\s+)?\{[^}]*\b(?:confirm|confirmOptions)\b[^}]*\}'.$from.'passkey'.$module.'#',
        'named confirmation import' => '#import\s+(?:type\s+)?\{[^}]*\bconfirmation\b[^}]*\}'.$from.'password'.$module.'#',
        'default or namespace import' => '#import\s+(?:type\s+)?(?:\*\s*as\s+\w+|\w+)\s*(?:,\s*\{[^}]*\})?'.$from.'(?:passkey|password)'.$module.'#',
        'dynamic import or re-export' => '#(?:import\s*\(\s*|export\s+\*'.$from.'|export\s*\{[^}]*\}'.$from.')[\'"]?(?:@/|(?:\.\./)+)?(?:routes/)?(?:passkey|password)'.$module.'#',
    ];

    return array_keys(array_filter($patterns, fn (string $pattern): bool => preg_match($pattern, $source) === 1));
}

test('the frontend never calls the passkey confirmation or the confirmed-password status endpoints', function () {
    // Wayfinder's generated actions/ and routes/ define them; nothing else may use them.
    $offenders = collect(File::allFiles(resource_path('js')))
        ->reject(fn (SplFileInfo $file): bool => preg_match('#^(actions|routes|wayfinder)/#', $file->getRelativePathname()) === 1)
        ->flatMap(fn (SplFileInfo $file): array => array_map(fn (string $why): string => $file->getRelativePathname().': '.$why, confirmEndpointUses($file->getContents())))
        ->values()->all();

    expect($offenders)->toBe([]);
});

test('the frontend guard catches each way of reaching those endpoints', function (string $source) {
    expect(confirmEndpointUses($source))->not->toBe([]);
})->with([
    "import { confirm } from '@/routes/passkey';",
    "import { confirmOptions as o } from '@/routes/passkey/index';",
    "import { confirmation } from '@/routes/password';",
    "import passkey from '@/routes/passkey'; passkey.confirm();",
    "import * as pk from '@/routes/passkey';",
    "import password from '@/routes/password/index'; password.confirmation();",
    "const m = await import('@/routes/passkey');",
    "export * from '@/routes/password';",
    "import PasskeyConfirmationController from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';",
    "fetch('/user/confirmed-password-status');",
]);

test('the frontend guard allows the imports in use today', function (string $source) {
    expect(confirmEndpointUses($source))->toBe([]);
})->with([
    "import { store } from '@/routes/password/confirm';",
    "import { confirm } from '@/routes/password';",
    "import { qrCode, recoveryCodes } from '@/routes/two-factor';",
    "import { index as registrationOptions } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';",
]);

/**
 * The security page loaded with a fresh password, then the window left to run out: what a person sees who opened it
 * and came back to it later. Returns the Inertia headers a visit from that page sends.
 *
 * @return array<string, string>
 */
function staleSecurityPage(User $user): array
{
    test()->actingAs($user)->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])->assertCreated();
    $version = (string) (test()->get(route('security.edit'))->assertOk()->viewData('page')['version'] ?? '');
    test()->travel(16)->minutes();

    return ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => route('security.edit')];
}

test('a refused two-factor step lands back on the security page with the dialog error, never on the confirm page', function (string $method, string $route, bool $enabled) {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    if ($enabled) {
        $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => encrypt(json_encode(['a', 'b'])), 'two_factor_confirmed_at' => now()])->save();
    }
    $headers = staleSecurityPage($user);

    $response = $this->withHeaders($headers)->followingRedirects()->call($method, route($route), $route === 'two-factor.confirm' ? ['code' => '123456'] : []);

    $response->assertOk()->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'settings/security')
        ->assertJsonPath('props.errors.'.RequireRecentPasswordConfirmation::ERROR_KEY, __('Confirm your password to continue.'));
    expect($response->json('url'))->not->toContain('confirm-password');
})->with([
    'enable' => ['POST', 'two-factor.enable', false],
    'confirm the code' => ['POST', 'two-factor.confirm', false],
    'regenerate recovery codes' => ['POST', 'two-factor.regenerate-recovery-codes', true],
    'disable' => ['DELETE', 'two-factor.disable', true],
]);

test('the refusal renders only the page this session last loaded with a password, and only once', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $headers = staleSecurityPage($user);

    // A visit claiming to come from another gated page gets the confirm page, as before.
    $this->withHeaders([...$headers, 'Referer' => route('profile.edit')])->post(route('two-factor.enable'))->assertRedirect(route('profile.edit'));
    $this->withHeaders($headers)->get(route('security.edit'))->assertRedirect(route('password.confirm'));

    // A plain page load (no Inertia) is never let through by the flash.
    $this->withHeaders($headers)->post(route('two-factor.enable'))->assertRedirect(route('security.edit'));
    $this->flushHeaders()->get(route('security.edit'))->assertRedirect(route('password.confirm'));
});

test('the confirm page leads back to the settings or the dashboard, and the configured window is shared', function () {
    config(['auth.step_up_seconds' => 600]);
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $this->actingAs($user)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    $this->get(route('password.confirm'))->assertInertia(fn ($page) => $page
        ->where('back.url', route('profile.edit'))
        ->where('auth.step_up_minutes', 10));

    $this->withSession(['url.intended' => route('two-factor.qr-code')])->get(route('password.confirm'))
        ->assertInertia(fn ($page) => $page->where('back.url', route('dashboard')));
});

test('a refused staff edit lands back on the staff form with the dialog error', function () {
    $admin = actingAsStaff();
    $admin->forceFill(['password' => 'correct-horse-battery'])->save();
    $staff = User::factory()->staff(['core.clients.view'])->create();
    $this->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])->assertCreated();
    $page = $this->get(route('admin.platform.staff.edit', $staff))->assertOk();
    $this->travel(16)->minutes();

    $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) ($page->viewData('page')['version'] ?? ''), 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => route('admin.platform.staff.edit', $staff)])
        ->followingRedirects()->put(route('admin.platform.staff.update', $staff), ['name' => 'Changed']);

    $response->assertOk()->assertJsonPath('component', $page->viewData('page')['component'])
        ->assertJsonPath('props.errors.'.RequireRecentPasswordConfirmation::ERROR_KEY, __('Confirm your password to continue.'));
    expect($staff->fresh()->name)->not->toBe('Changed');
});
