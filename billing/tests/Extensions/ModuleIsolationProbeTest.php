<?php

use App\Contracts\Auth\SignInGate;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Core\Support\AllowAllSignInGate;
use App\Modules\Platform\PlatformServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Security review of #112: ModuleRoutesResolveTest really boots one module, and its check would see an alias that
 * nothing registers. Core's allow-all gate is what runs only when Platform is absent.
 */
afterEach(fn () => TestCase::$onlyModules = null);

test('MODULES=Billing boots Billing and Core without Platform, and password.recent is the kernel middleware', function () {
    TestCase::$onlyModules = 'Billing';
    $this->refreshApplication();
    app(Kernel::class);

    expect(app()->getProviders(PlatformServiceProvider::class))->toBe([])
        ->and(app()->getProviders(BillingServiceProvider::class))->not->toBe([])
        ->and(app('router')->getMiddleware()['password.recent'] ?? null)->toBe(RequireRecentPasswordConfirmation::class)
        ->and(app(SignInGate::class))->toBeInstanceOf(AllowAllSignInGate::class)
        ->and(collect(Route::getRoutes()->getRoutes())->contains(fn ($route) => in_array('password.recent', $route->gatherMiddleware(), true)))->toBeTrue();
});

test('the route-resolution check flags an alias that no module registers', function () {
    TestCase::$onlyModules = 'Billing';
    $this->refreshApplication();
    app(Kernel::class);
    $route = Route::middleware(['web', 'probe.unregistered'])->post('/_probe/alias', fn () => 'ok');

    $unresolved = collect(app('router')->gatherRouteMiddleware($route))
        ->filter(fn ($middleware) => is_string($middleware) && ! class_exists(explode(':', $middleware, 2)[0]))
        ->values()->all();

    expect($unresolved)->toBe(['probe.unregistered']);
});
