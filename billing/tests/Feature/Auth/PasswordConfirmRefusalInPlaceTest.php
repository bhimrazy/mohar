<?php

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * Re-review 2 probes for #538 (review/pr-538-probes, cb2190ba), adopted with the safe behaviour: a refused mutation is
 * a 423 answered in place, and no page or JSON endpoint behind password.confirm is served without a recent password.
 */

/** @return array{0: User, 1: string} a login with two-factor on, its password confirmed, and the Inertia version */
function rr2Confirmed(): array
{
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => encrypt(json_encode(['old-1', 'old-2'])), 'two_factor_confirmed_at' => now()])->save();
    test()->actingAs($user)->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])->assertCreated();
    $version = (string) (test()->get(route('security.edit'))->assertOk()->viewData('page')['version'] ?? '');

    return [$user, $version];
}

test('Q1 a JSON endpoint behind password.confirm loaded as a non-JSON GET in the window is not readable after it', function () {
    [$user, $version] = rr2Confirmed();
    $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'Accept' => 'text/html'];

    $this->withHeaders($inertia)->get(route('two-factor.recovery-codes'))->assertOk();

    $this->travel(2)->hours();
    $user->forceFill(['two_factor_recovery_codes' => encrypt(json_encode(['new-1', 'new-2']))])->save();

    foreach ([1, 2, 3] as $round) {
        $this->withHeaders([...$inertia, 'Referer' => route('two-factor.recovery-codes')])->post(route('two-factor.regenerate-recovery-codes'))
            ->assertStatus(423)->assertHeader(RequireRecentPasswordConfirmation::REFUSAL_HEADER, '1');
        $response = $this->withHeaders($inertia)->get(route('two-factor.recovery-codes'));
        $response->assertRedirect(route('password.confirm'));
        expect((string) $response->getContent())->not->toContain('new-1');
    }
    expect(json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true))->toBe(['new-1', 'new-2']);
});

test('Q2 refusals never open the security page, however many there are', function () {
    [, $version] = rr2Confirmed();
    $this->travel(16)->minutes();
    $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => route('security.edit')];

    foreach ([1, 2, 3] as $round) {
        $this->withHeaders($inertia)->post(route('two-factor.regenerate-recovery-codes'))->assertStatus(423);
        $this->withHeaders($inertia)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    }
});

test('Q3 a stale-version GET after a refusal opens nothing either', function () {
    [, $version] = rr2Confirmed();
    $this->travel(16)->minutes();
    $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => route('security.edit')];

    $this->withHeaders($inertia)->post(route('two-factor.enable'))->assertStatus(423);
    $this->withHeaders([...$inertia, 'X-Inertia-Version' => 'stale'])->get(route('security.edit'))->assertStatus(409)->assertHeader('X-Inertia-Location');
    $this->withHeaders($inertia)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
});

test('no request shape gets two-factor secrets, codes or the QR code from a password.confirm route without a recent password', function (array $session) {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => collect(app('router')->gatherRouteMiddleware($route))
            ->contains(fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'Illuminate\Auth\Middleware\RequirePassword')))
        ->values();
    expect($routes->count())->toBeGreaterThan(10);

    $staff = actingAsStaff();
    $staff->forceFill(['password' => 'correct-horse-battery', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => encrypt(json_encode(['code-aaaa-1111', 'code-bbbb-2222'])), 'two_factor_confirmed_at' => now()])->save();
    $passkey = $staff->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'cred-x', 'credential' => []]);
    $version = (string) ($this->withSession([RequireRecentPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])->get(route('security.edit'))->viewData('page')['version'] ?? '');

    $shapes = [
        'plain' => [],
        'json' => ['Accept' => 'application/json'],
        'xhr' => ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*'],
        'inertia' => ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'],
        'inertia-json' => ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'Accept' => 'application/json'],
        'prefetch' => ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'Purpose' => 'prefetch', 'Sec-Purpose' => 'prefetch'],
        'probe' => ['Accept' => 'application/json', RequireRecentPasswordConfirmation::PROBE_HEADER => '1'],
        'probe-plain' => [RequireRecentPasswordConfirmation::PROBE_HEADER => '1'],
    ];
    $leaks = [];

    foreach ($routes as $route) {
        $url = '/'.strtr($route->uri(), ['{passkey}' => $passkey->id, '{staff}' => $staff->id, '{role}' => $staff->role_id]);

        foreach (array_unique([$route->methods()[0], 'HEAD']) as $method) {
            foreach ($shapes as $label => $headers) {
                $this->flushSession();
                $this->actingAs($staff)->withSession($session);
                $body = (string) $this->call($method, $url, [], [], [], $this->transformHeadersToServerVars($headers))->getContent();

                foreach (['code-aaaa-1111', 'JBSWY3DPEHPK3PXP', '<svg'] as $secret) {
                    if (str_contains($body, $secret)) {
                        $leaks[] = "{$method} {$url} [{$label}] {$secret}";
                    }
                }
            }
        }
    }

    // The scan can see a leak: with a recent password the same endpoints do return them.
    $this->flushSession();
    $this->actingAs($staff)->withSession([RequireRecentPasswordConfirmation::SESSION_KEY => now()->getTimestamp()]);
    $this->getJson(route('two-factor.recovery-codes'))->assertSee('code-aaaa-1111');
    $this->getJson(route('two-factor.qr-code'))->assertSee('<svg', false);

    expect($leaks)->toBe([])
        ->and($staff->fresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and($staff->passkeys()->count())->toBe(1);
})->with([
    'no confirmation' => [[]],
    'a passkey confirmation (Laravel key)' => [['auth.password_confirmed_at' => now()->getTimestamp()]],
    'an expired password confirmation' => [[RequireRecentPasswordConfirmation::SESSION_KEY => now()->subMinutes(16)->getTimestamp()]],
]);
