<?php

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\User;
use App\Modules\Core\Models\Client;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;

/**
 * ADR 0018 amendment (#459): an expired one-time password confirms nothing. Every route behind a password
 * confirmation, Laravel's `password.confirm` (3-hour window) or `password.recent` (step-up), is called by a login whose
 * one-time password expired after the session confirmed it, and must be refused before the controller runs.
 */
function expiredConfirmationRoutes(): array
{
    $confirms = fn (mixed $middleware): bool => is_string($middleware) && (
        preg_match('/^password\.(confirm|recent)(:|$)/', $middleware) === 1
        || str_starts_with($middleware, RequirePassword::class)
        || str_starts_with($middleware, RequireRecentPasswordConfirmation::class)
    );

    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => $route->getName() !== null && collect($route->gatherMiddleware())->contains($confirms))
        ->all();
}

function refusedAsUnconfirmed(TestResponse $response): bool
{
    if ($response->status() === 423 || ($response->isRedirect() && str_starts_with((string) $response->headers->get('Location'), route('password.confirm')))) {
        return true;
    }

    try {
        $response->assertSessionHasErrors(RequireRecentPasswordConfirmation::ERROR_KEY);

        return true;
    } catch (AssertionFailedError) {
        return false;
    }
}

test('every route behind a password confirmation refuses an expired one-time password confirmed before it expired', function () {
    Storage::fake('objects');
    config(['features' => array_map(fn ($module) => is_array($module) ? array_map(fn () => true, $module) : true, config('features'))]);
    // The refusal is middleware; without binding, a route answers 404 for a missing model before it runs.
    $this->withoutMiddleware(SubstituteBindings::class);
    $routes = expiredConfirmationRoutes();
    $seen = [];
    $leaked = [];

    foreach ($routes as $route) {
        $name = $route->getName();
        // Staff with 2FA, so the staff-security redirect does not answer first; every permission, so none refuses first.
        $user = User::factory()->staff(['*'])->withTwoFactor()->create(['password_expires_at' => now()->addMinute()])->refresh();
        // Linked to a client account, so client-area routes reach the confirmation instead of redirecting staff away.
        $user->clients()->attach(Client::factory()->create(), ['is_owner' => true]);
        $this->flushSession();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
        $this->travel(2)->minutes();

        // Bindings are skipped (below), so a parameter needs only a value its route constraint accepts.
        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $pattern = $route->wheres[$parameter] ?? null;
            $candidates = is_string($pattern) && preg_match('/^[a-z0-9_|-]+$/', $pattern) === 1 ? explode('|', $pattern) : ['1', 'a'];
            $parameters[$parameter] = collect($candidates)->first(fn (string $value): bool => ! is_string($pattern) || preg_match('#^(?:'.$pattern.')$#', $value) === 1, '1');
        }

        $method = collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD');
        $response = $this->call($method, route($name, $parameters), [], [], [], ['HTTP_ACCEPT' => 'text/html', 'HTTP_REFERER' => url('/')]);
        $seen[] = $name;

        if (! refusedAsUnconfirmed($response)) {
            $leaked[] = "{$name} ({$response->status()})";
        }
    }

    expect($leaked)->toBe([])
        // Vacuity guard: both middleware families and the Fortify routes are in the scan.
        ->and($seen)->toContain('security.edit', 'passkey.registration-options', 'two-factor.disable', 'admin.platform.roles.store', 'admin.billing.invoices.bulk', 'client.services.password.reveal');
});
