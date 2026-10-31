<?php

namespace Tests\Support\Registries;

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Support\Automation\Automation;
use App\Support\Clients\ClientCurrencyUsage;
use App\Support\Dashboard\Dashboard;
use App\Support\Events\WebhookEvents;
use App\Support\Install\ReferenceDataRegistry;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Security\EncryptedStorage;
use App\Support\Seo\SitemapRegistry;
use App\Support\Settings\SettingsRegistry;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionObject;

/**
 * Records what one module put into the kernel registries after boot, so a refactor of its service provider
 * (S4, the Boot/ split) can prove it registered exactly the same things.
 *
 * Registries keep their entries in private arrays, so the snapshot reads them by reflection instead of widening
 * the kernel API. Closures are recorded by signature only (their bodies are not comparable); the entry that holds
 * them is recorded in registration order. A module's entries are the ones whose id (permission, setting, route,
 * task or webhook key) matches the module's pattern. Regenerate a fixture with `UPDATE_REGISTRY_SNAPSHOT=1`.
 */
final class RegistrySnapshot
{
    /** Registries read by reflection: label => class (properties holding the entries are all private arrays). */
    private const REGISTRIES = [
        'permissions' => PermissionRegistry::class,
        'settings' => SettingsRegistry::class,
        'navigation' => Navigation::class,
        'search' => GlobalSearch::class,
        'webhooks' => WebhookEvents::class,
        'automation' => Automation::class,
        'dashboard' => Dashboard::class,
        'sitemap' => SitemapRegistry::class,
        'currency_usage' => ClientCurrencyUsage::class,
        'merge_fields' => MergeFieldRegistry::class,
        'reference_data' => ReferenceDataRegistry::class,
        'encrypted_storage' => EncryptedStorage::class,
    ];

    /**
     * @param  string  $pattern  PCRE matching the identifiers (permission, setting, route, task, webhook keys) of the module
     * @param  string  $namespace  the module's PHP namespace, used to pick its event listeners and middleware
     * @return array<string, mixed>
     */
    public static function capture(string $pattern, string $namespace): array
    {
        $snapshot = [];

        foreach (self::REGISTRIES as $label => $class) {
            $registry = app($class);
            $entries = [];

            foreach ((new ReflectionObject($registry))->getProperties() as $property) {
                $value = $property->getValue($registry);

                if (! is_array($value)) {
                    continue;
                }

                foreach (self::flatten($property->getName(), $value) as $id => $entry) {
                    $normalised = self::normalise($entry);

                    if (preg_match($pattern, $id) === 1) {
                        $entries[$id] = $normalised;
                    }
                }
            }

            $snapshot[$label] = $entries;
        }

        $snapshot['event_listeners'] = self::eventListeners($namespace);
        $snapshot['rate_limiters'] = self::rateLimiters($pattern);
        $snapshot['web_middleware'] = array_values(array_filter(
            app(HttpKernel::class)->getMiddlewareGroups()['web'] ?? [],
            fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, $namespace.'\\'),
        ));

        return $snapshot;
    }

    /**
     * One id per registered entry: "<property>/<key>" or, where entries nest, "<property>/<area>/<key>".
     *
     * @return array<string, mixed>
     */
    private static function flatten(string $property, array $value): array
    {
        $flat = [];

        foreach ($value as $key => $entry) {
            $nested = match ($property) {
                'groups', 'widgets', 'todos', 'health', 'cards' => true,
                'items' => $property === 'items' && is_array($entry) && array_is_list($entry),
                'providers' => false,
                default => false,
            };

            if ($property === 'sections') {
                $flat["$property/$key/_meta"] = ['title' => $entry['title'], 'permission' => $entry['permission']];

                foreach ($entry['settings'] as $definition) {
                    $flat["$property/$key/{$definition->key}"] = $definition;
                }

                continue;
            }

            if ($property === 'providers') {
                $flat["$property/".($entry['permission'] ?? $entry['group'])] = $entry;

                continue;
            }

            if (! $nested || ! is_array($entry)) {
                // A list of plain strings (sensitive permissions) is identified by its value, not its position.
                $flat[is_string($entry) && is_int($key) ? "$property/$entry" : "$property/$key"] = $entry;

                continue;
            }

            foreach ($entry as $index => $item) {
                $flat["$property/$key/".(match (true) {
                    is_array($item) && isset($item['key']) => $item['key'],
                    $item instanceof NavItem => $item->route,
                    default => $index,
                })] = $item;
            }
        }

        return $flat;
    }

    /** @return array<string, list<mixed>> */
    private static function eventListeners(string $namespace): array
    {
        $dispatcher = app(Dispatcher::class);
        $listeners = (new ReflectionObject($dispatcher))->getProperty('listeners')->getValue($dispatcher);
        $found = [];

        foreach ($listeners as $event => $registered) {
            foreach ($registered as $listener) {
                $normalised = self::normalise($listener);

                if (str_contains(str_replace('\\\\', '\\', self::text($normalised)), $namespace.'\\')) {
                    $found[$event][] = $normalised;
                }
            }
        }

        ksort($found);

        return $found;
    }

    /** @return array<string, array{max: int, decay: int, key: string}> */
    private static function rateLimiters(string $pattern): array
    {
        $limiter = app(RateLimiter::class);
        $limiters = (new ReflectionObject($limiter))->getProperty('limiters')->getValue($limiter);
        $found = [];

        foreach ($limiters as $name => $callback) {
            if (preg_match($pattern, (string) $name) !== 1) {
                continue;
            }

            $limit = $callback(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']));
            $found[$name] = ['max' => $limit->maxAttempts, 'decay' => $limit->decaySeconds, 'key' => $limit->key];
        }

        ksort($found);

        return $found;
    }

    private static function normalise(mixed $value, int $depth = 0): mixed
    {
        if ($value instanceof Closure) {
            $reflection = new ReflectionFunction($value);

            return 'Closure('.implode(', ', array_map(
                fn ($parameter): string => ($parameter->getType() instanceof ReflectionNamedType ? $parameter->getType()->getName() : 'mixed').' $'.$parameter->getName(),
                $reflection->getParameters(),
            )).')';
        }

        if (is_object($value)) {
            if ($depth > 4 || $value instanceof \UnitEnum) {
                return $value instanceof \UnitEnum ? $value::class.'::'.$value->name : $value::class;
            }

            $state = [];

            foreach ((new ReflectionObject($value))->getProperties() as $property) {
                if ($property->isInitialized($value)) {
                    $state[$property->getName()] = self::normalise($property->getValue($value), $depth + 1);
                }
            }

            return ['class' => $value::class] + $state;
        }

        if (is_array($value)) {
            return array_map(fn ($item) => self::normalise($item, $depth + 1), $value);
        }

        return $value;
    }

    private static function text(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** Asserts the module's snapshot equals tests/Fixtures/Registries/<module>.json (or rewrites it on request). */
    public static function assertMatchesFixture(string $module, string $pattern, string $namespace): void
    {
        $path = dirname(__DIR__, 2).'/Fixtures/Registries/'.$module.'.json';
        $current = json_encode(self::capture($pattern, $namespace), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if (getenv('UPDATE_REGISTRY_SNAPSHOT') === '1') {
            file_put_contents($path, $current);
        }

        expect($current)->toBe((string) file_get_contents($path));
    }
}
