<?php

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * Re-review 3 probes for #538 (head 944923a7): the 423 design, every request shape, after a confirmed load.
 */

function rr3ConfirmRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => collect(app('router')->gatherRouteMiddleware($route))
            ->contains(fn ($m): bool => is_string($m) && str_starts_with($m, 'Illuminate\Auth\Middleware\RequirePassword')))
        ->values()->all();
}

test('R3-1 after a confirmed load of every password.confirm GET in every shape, nothing secret is served once the window ends', function () {
    $staff = actingAsStaff();
    $staff->forceFill(['password' => 'correct-horse-battery', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => encrypt(json_encode(['code-aaaa-1111'])), 'two_factor_confirmed_at' => now()])->save();
    $this->withSession([RequireRecentPasswordConfirmation::SESSION_KEY => now()->getTimestamp()]);
    $version = (string) ($this->get(route('security.edit'))->viewData('page')['version'] ?? '');
    $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'];
    $shapes = [
        'inertia-html' => [...$inertia, 'Accept' => 'text/html'],
        'inertia-json' => [...$inertia, 'Accept' => 'application/json'],
        'stale-version' => [...$inertia, 'X-Inertia-Version' => 'stale'],
        'partial' => [...$inertia, 'X-Inertia-Partial-Component' => 'settings/security', 'X-Inertia-Partial-Data' => 'recoveryCodes,qrCode,secretKey,twoFactorEnabled'],
        'partial-except' => [...$inertia, 'X-Inertia-Partial-Component' => 'settings/security', 'X-Inertia-Partial-Except' => 'passkeys'],
        'prefetch' => [...$inertia, 'Purpose' => 'prefetch', 'Sec-Purpose' => 'prefetch'],
        'json' => ['Accept' => 'application/json'],
        'xhr-any' => ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*'],
        'probe' => [RequireRecentPasswordConfirmation::PROBE_HEADER => '1', 'Accept' => 'application/json'],
        'plain' => [],
    ];
    $gets = collect(rr3ConfirmRoutes())->filter(fn ($r) => in_array('GET', $r->methods()))
        ->map(fn ($r) => '/'.strtr($r->uri(), ['{staff}' => $staff->id, '{role}' => $staff->role_id]))->values();
    expect($gets)->toContain('/user/two-factor-recovery-codes', '/user/two-factor-qr-code', '/user/two-factor-secret-key', '/settings/security');

    // In the window: every shape on every GET (seeds anything a pass-through could key on).
    foreach ($gets as $url) {
        foreach ($shapes as $headers) {
            $this->call('GET', $url, [], [], [], $this->transformHeadersToServerVars([...$headers, 'Referer' => url($url)]));
        }
    }

    $this->travel(16)->minutes();
    $leaks = [];
    foreach ([1, 2] as $round) {
        foreach ($gets as $url) {
            // A refused mutation from that "page", then every shape again, GET and HEAD.
            $this->call('POST', '/user/two-factor-recovery-codes', [], [], [], $this->transformHeadersToServerVars([...$inertia, 'Referer' => url($url)]));
            foreach ($shapes as $label => $headers) {
                foreach (['GET', 'HEAD'] as $method) {
                    $response = $this->call($method, $url, [], [], [], $this->transformHeadersToServerVars([...$headers, 'Referer' => url($url)]));
                    $body = (string) $response->getContent();
                    foreach (['code-aaaa-1111', 'JBSWY3DPEHPK3PXP', '<svg', 'otpauth://'] as $secret) {
                        if (str_contains($body, $secret)) {
                            $leaks[] = "{$method} {$url} [{$label}] {$secret}";
                        }
                    }
                    if ($response->getStatusCode() === 200 && $method === 'GET' && $label !== 'probe') {
                        $leaks[] = "{$method} {$url} [{$label}] 200";
                    }
                }
            }
        }
    }

    expect($leaks)->toBe([]);
    // Positive control for the same loop: inside a window the scan sees the codes.
    $this->withSession([RequireRecentPasswordConfirmation::SESSION_KEY => now()->getTimestamp()]);
    expect((string) $this->call('GET', '/user/two-factor-recovery-codes', [], [], [], $this->transformHeadersToServerVars(['Accept' => 'application/json']))->getContent())->toContain('code-aaaa-1111');
});

test('R3-2 an Inertia mutation on password.recent gets the bare 423, a plain post the session error, and nothing runs', function () {
    $staff = actingAsStaff();
    $staff->forceFill(['password' => 'correct-horse-battery'])->save();
    $this->withSession([RequireRecentPasswordConfirmation::SESSION_KEY => now()->subMinutes(16)->getTimestamp()]);

    $this->post(route('admin.platform.staff.store'), ['name' => 'X', 'email' => 'x@example.com.np'], ['X-Inertia' => 'true', 'Referer' => route('admin.dashboard')])
        ->assertStatus(423)->assertHeader(RequireRecentPasswordConfirmation::REFUSAL_HEADER, '1')->assertHeaderMissing('X-Inertia')->assertHeaderMissing('Location');
    $this->from(route('admin.dashboard'))->post(route('admin.platform.staff.store'), ['name' => 'X', 'email' => 'x@example.com.np'])
        ->assertRedirect(route('admin.dashboard'))->assertSessionHasErrors(RequireRecentPasswordConfirmation::ERROR_KEY)->assertSessionMissing('_old_input');
    // JSON clients keep the 423 without the Inertia header.
    $this->postJson(route('admin.platform.staff.store'), [])->assertStatus(423)->assertHeaderMissing(RequireRecentPasswordConfirmation::REFUSAL_HEADER);
    expect(User::query()->where('email', 'x@example.com.np')->exists())->toBeFalse();
});
