<?php

use App\Modules\Services\Models\ServerGroup;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Security standards §2: every route that changes something behind a sensitive permission needs a recent password
 * confirmation. The permissions a route authorises are read from its controller method, the private helpers that
 * method calls, and its FormRequest's authorize(): every quoted string that is a registered permission counts.
 * (Security review 2026-10-19, L1.)
 */

/** Non-GET routes behind a sensitive permission that may run without step-up, and why. */
const STEP_UP_EXEMPT = [
    // Read-only towards our data: they ask the gateway for a status and store what it reports.
    'admin.billing.gateways.test' => 'Test connection sends no stored data anywhere it was not saved for.',
    'admin.billing.payment-attempts.check' => 'Asks the gateway for the status of an attempt; changes nothing staff did not already authorise.',
    // CurrencyRequest asks for core.currencies.rates only when creating; an update never changes the rate.
    'admin.platform.currencies.update' => 'The rates permission applies to create only, which has step-up.',
];

/**
 * Source text of a method plus the same-class methods it calls ($this->name(...)), one level deep.
 */
function stepUpScanSource(ReflectionMethod $method, int $depth = 1): string
{
    $file = $method->getFileName();

    if ($file === false) {
        return '';
    }

    $lines = array_slice(file($file) ?: [], $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
    $source = implode('', $lines);

    if ($depth > 0 && preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\(/', $source, $calls) > 0) {
        $class = $method->getDeclaringClass();

        foreach (array_unique($calls[1]) as $name) {
            if ($class->hasMethod($name) && $class->getMethod($name)->getDeclaringClass()->getName() === $class->getName()) {
                $source .= stepUpScanSource($class->getMethod($name), $depth - 1);
            }
        }
    }

    return $source;
}

/**
 * @return list<string> registered permissions the route's action authorises
 */
function permissionsAuthorisedBy(RoutingRoute $route, PermissionRegistry $permissions): array
{
    $uses = $route->getAction('uses');

    if (! is_string($uses) || ! str_contains($uses, '@')) {
        return [];
    }

    [$class, $name] = explode('@', $uses);

    if (! method_exists($class, $name)) {
        return [];
    }

    $method = new ReflectionMethod($class, $name);
    $source = stepUpScanSource($method);

    foreach ($method->getParameters() as $parameter) {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class) && method_exists($type->getName(), 'authorize')) {
            $source .= stepUpScanSource(new ReflectionMethod($type->getName(), 'authorize'));
        }
    }

    foreach ($route->gatherMiddleware() as $middleware) {
        if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
            $source .= "'".explode(',', substr($middleware, 4))[0]."'";
        }
    }

    preg_match_all("/'([a-z][a-z0-9_]*(?:\.[a-z0-9_*]+)+)'/", $source, $matches);

    return array_values(array_unique(array_filter($matches[1], fn (string $key): bool => $permissions->exists($key))));
}

/**
 * @return array<string, list<string>> route name (or URI) => the sensitive permissions it authorises
 */
function sensitiveMutatingRoutes(): array
{
    $permissions = app(PermissionRegistry::class);
    $found = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) === []) {
            continue;
        }

        $sensitive = array_values(array_filter(permissionsAuthorisedBy($route, $permissions), $permissions->isSensitive(...)));

        if ($sensitive !== []) {
            $found[$route->getName() ?? implode('|', $route->methods()).' '.$route->uri()] = $sensitive;
        }
    }

    return $found;
}

test('every non-GET route behind a sensitive permission requires a recent password confirmation', function () {
    $routes = Route::getRoutes();
    $missing = [];

    foreach (sensitiveMutatingRoutes() as $name => $sensitive) {
        $route = $routes->getByName($name);

        if (array_key_exists($name, STEP_UP_EXEMPT) || ($route !== null && in_array('password.recent', $route->gatherMiddleware(), true))) {
            continue;
        }

        $missing[] = $name.' ('.implode(', ', $sensitive).')';
    }

    expect($missing)->toBe([]);
});

test('the step-up scan sees the sensitive routes it guards, so it cannot pass by finding nothing', function () {
    $found = sensitiveMutatingRoutes();

    expect($found)->toHaveKeys([
        'admin.services.servers.update',
        'admin.services.server-groups.store',
        'admin.billing.invoices.capture',
        'admin.platform.roles.store',
    ])->and(array_diff(array_keys(STEP_UP_EXEMPT), array_keys($found)))->toBe([]);
});

test('probe L1: server-group changes need a recent password confirmation', function () {
    actingAsStaff(['services.servers.view', 'services.servers.manage']);

    $this->post(route('admin.services.server-groups.store'), ['name' => 'Shared EU', 'fill_type' => 'least_full', 'server_ids' => []])
        ->assertSessionHasErrors('password_confirmation');

    expect(ServerGroup::query()->count())->toBe(0);

    confirmPassword();
    $this->post(route('admin.services.server-groups.store'), ['name' => 'Shared EU', 'fill_type' => 'least_full', 'server_ids' => []])
        ->assertSessionHasNoErrors();

    expect(ServerGroup::query()->count())->toBe(1);
});
