<?php

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Tests\TestCase;

/*
 * Every module works without its siblings (ADR 0001): booted alone (MODULES=<module>; Core always loads), each route
 * it registers resolves its middleware to a class the container can build, and its controller exists. Catches an
 * alias that only another module registers, as `password.recent` was before it moved to the kernel (#81, ADR 0018 §4).
 */

afterEach(fn () => TestCase::$onlyModules = null);

test('each module boots alone and every route resolves its middleware', function (string $module) {
    TestCase::$onlyModules = $module;
    $this->refreshApplication();
    // The HTTP kernel hands its aliases and groups to the router when it is built, as it does for a real request.
    app(Kernel::class);
    $router = app(Router::class);
    $unresolved = [];

    expect($router->getMiddleware()['password.recent'] ?? null)->toBe(RequireRecentPasswordConfirmation::class);

    foreach ($router->getRoutes()->getRoutes() as $route) {
        $label = $route->methods()[0].' '.$route->uri();

        foreach ($router->gatherRouteMiddleware($route) as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $class = explode(':', $middleware, 2)[0];

            try {
                app()->make($class);
            } catch (Throwable $e) {
                $unresolved[] = "{$label}: middleware {$middleware} ({$e->getMessage()})";
            }
        }

        $uses = $route->getAction('uses');

        if (is_string($uses) && ! class_exists(explode('@', $uses)[0])) {
            $unresolved[] = "{$label}: controller {$uses}";
        }
    }

    expect($unresolved)->toBe([]);
})->with(array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR) ?: []));
