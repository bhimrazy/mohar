<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;

/*
 * A route gated with `feature:<module>.<flag>` must name a key that exists in config/features.php, or the gate would
 * answer 404 forever (a typo) or, with a missing key, hide the route for good.
 */
test('every feature: route middleware names an existing features.* key', function () {
    $named = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($route->middleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'feature:')) {
                $named[substr($middleware, 8)][] = $route->getName() ?? $route->uri();
            }
        }
    }

    expect($named)->not->toBeEmpty();

    foreach (array_keys($named) as $flag) {
        expect(Arr::has(config('features'), $flag))->toBeTrue("feature:{$flag} is not a key of config/features.php");
    }
});

