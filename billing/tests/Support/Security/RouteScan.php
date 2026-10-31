<?php

namespace Tests\Support\Security;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionNamedType;

/**
 * Route parameters and bodies for the admin route scans (step-up and permission sweeps).
 */
final class RouteScan
{
    /** Route key that binds to an empty model, for models without a factory or in a state a fresh one cannot reach. */
    public const BLANK = '987654321';

    /**
     * @return array<string, class-string<Model>> route parameter => bound model class
     */
    public static function modelParameters(RoutingRoute $route): array
    {
        $bound = [];

        foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Model::class)) {
                $bound[$parameter->getName()] = $type->getName();
            }
        }

        $models = [];

        foreach ($route->parameterNames() as $name) {
            $class = $bound[$name] ?? $bound[Str::camel($name)] ?? null;

            if ($class !== null) {
                $models[$name] = $class;
            }
        }

        return $models;
    }

    /**
     * Binds BLANK to an empty model of the route's type; any other key resolves as implicit binding would.
     */
    public static function binder(string $name): void
    {
        static $bound = [];
        $key = spl_object_id(app('router')).'|'.$name;

        if (isset($bound[$key])) {
            return;
        }

        $bound[$key] = true;
        Route::bind($name, function (string $value, RoutingRoute $route) use ($name): Model {
            $class = self::modelParameters($route)[$name];

            return $value === self::BLANK ? new $class : ((new $class)->resolveRouteBinding($value) ?? throw (new ModelNotFoundException)->setModel($class));
        });
    }

    /**
     * A value for each route parameter: a fresh factory model (children get their parent's id), BLANK when $blank or
     * the model has no factory, or a known key for string parameters. Null when one cannot be resolved.
     *
     * @return array<string, mixed>|null
     */
    public static function parameters(RoutingRoute $route, ?string $section, bool $blank): ?array
    {
        $models = self::modelParameters($route);
        $values = [];
        $parents = [];

        foreach ($route->parameterNames() as $name) {
            $class = $models[$name] ?? null;

            if ($class !== null) {
                if ($blank || ! method_exists($class, 'factory')) {
                    self::binder($name);
                    $values[$name] = self::BLANK;

                    continue;
                }

                $model = new $class;
                $attributes = [];

                foreach ($parents as $parentName => $parent) {
                    if (Schema::hasColumn($model->getTable(), "{$parentName}_id")) {
                        $attributes["{$parentName}_id"] = $parent->getKey();
                    }
                }

                $values[$name] = $parents[Str::snake($name)] = $class::factory()->create($attributes);

                continue;
            }

            $values[$name] = $name === 'section' ? $section : self::stringParameter($route, $name);

            if ($values[$name] === null) {
                return null;
            }
        }

        return $values;
    }

    public static function stringParameter(RoutingRoute $route, string $name): ?string
    {
        $pattern = $route->wheres[$name] ?? null;

        // whereIn() constraints list the allowed values; take the first.
        if (is_string($pattern) && preg_match('/^[a-z0-9_|-]+$/', $pattern) === 1) {
            return explode('|', $pattern)[0];
        }

        return [
            'registrar' => 'log',
            'gateway' => 'sandbox',
            'kind' => 'mark',
            'template' => 'client.welcome',
            'converter' => 'products',
            'notice' => 'company_time_settings',
            'key' => 'sweep-theme',
        ][$name] ?? null;
    }

    /**
     * Request bodies for routes that validate before they authorise, one per permission the route can check (#107).
     *
     * @return array<string, array<string, array<string, mixed>>> route => variant => body
     */
    public static function bodies(): array
    {
        return [
            'admin.billing.invoices.bulk' => ['mark_paid' => ['action' => 'mark_paid', 'ids' => [1]], 'cancel' => ['action' => 'cancel', 'ids' => [1]], 'remind' => ['action' => 'remind', 'ids' => [1]]],
            'admin.orders.bulk' => ['accept' => ['action' => 'accept', 'ids' => [1]], 'cancel' => ['action' => 'cancel', 'ids' => [1]], 'delete' => ['action' => 'delete', 'ids' => [1]]],
            'admin.services.services.change' => ['change' => ['product_id' => 1, 'cycle' => 'monthly']],
            'admin.services.services.cancel' => ['cancel' => ['type' => 'end_of_period', 'reason' => 'Step-up scan']],
        ];
    }
}
