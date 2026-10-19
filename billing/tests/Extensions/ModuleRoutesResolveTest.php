<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every module works without its siblings (ADR 0001): booted alone (MODULES=<module>, Core always loads), each of its
 * routes' middleware and controllers resolve. Catches an alias only another module registers, as `password.recent`
 * was (#81, ADR 0018 §4).
 */
afterEach(fn () => TestCase::$onlyModules = null);

test('each module boots alone and every route it registers resolves', function (string $module) {
    TestCase::$onlyModules = $module;
    $this->refreshApplication();
    // The HTTP kernel hands its aliases and groups to the router when it is built, as for a real request.
    app(Kernel::class);
    $unresolved = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach (app('router')->gatherRouteMiddleware($route) as $middleware) {
            $class = is_string($middleware) ? explode(':', $middleware, 2)[0] : null;

            if ($class !== null && ! class_exists($class) && ! interface_exists($class)) {
                $unresolved[] = ($route->getName() ?? $route->uri()).' middleware '.$middleware;
            }
        }

        $uses = $route->getAction('uses');

        if (is_string($uses) && ! class_exists(explode('@', $uses)[0])) {
            $unresolved[] = ($route->getName() ?? $route->uri()).' controller '.$uses;
        }
    }

    expect($unresolved)->toBe([]);
})->with(array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR) ?: []));
