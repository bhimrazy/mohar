<?php

namespace App\Support\Migration;

use App\Contracts\Migration\RecordImporter;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * The record importers modules register (client-import spec §16 C1, ADR 0012), bound as a singleton in
 * AppServiceProvider so a module registers in its own boot() whether or not the Migration module is loaded:
 *
 *   $this->app->make(ImportRegistry::class)->register('client', ClientImporter::class);
 *   $this->app->make(ImportRegistry::class)->register('user', LoginImporter::class, dependsOn: ['client']);
 *   $this->app->make(ImportRegistry::class)->register('invoice', InvoiceImporter::class, dependsOn: ['client'], after: ['domain']);
 *
 * dependsOn is hard: the entity cannot be imported without those. after only orders: the entity runs after those
 * entities when they are part of the same import, and the import works without them (a module that is not loaded,
 * an importer not built yet).
 *
 * The key is the entity: the same string is ImportRecord::$entity and the id map's entity. register() resolves
 * nothing; get() builds the importer through the container and checks it reports its key.
 *
 * @api
 */
final class ImportRegistry
{
    private const KEY_PATTERN = '/^[a-z0-9_]{1,50}$/';

    /** @var array<string, array{importer: class-string<RecordImporter>|Closure(Container): RecordImporter, dependsOn: list<string>, after: list<string>}> */
    private array $importers = [];

    public function __construct(private Container $container) {}

    /**
     * @param  class-string<RecordImporter>|Closure(Container): RecordImporter  $importer
     * @param  list<string>  $dependsOn  entities that must be imported first
     * @param  list<string>  $after  entities imported first when they are part of the same import; never required
     */
    public function register(string $key, string|Closure $importer, bool $replace = false, array $dependsOn = [], array $after = []): void
    {
        foreach ([$key, ...$dependsOn, ...$after] as $name) {
            if (preg_match(self::KEY_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException("Invalid import entity [{$name}]: use 1 to 50 lowercase letters, digits or underscores.");
            }
        }

        if (isset($this->importers[$key])) {
            if (! $replace) {
                throw new LogicException("An importer is already registered for [{$key}]; pass replace: true to override it.");
            }

            Log::info('Record importer replaced.', ['entity' => $key]);
        }

        $this->importers[$key] = ['importer' => $importer, 'dependsOn' => $dependsOn, 'after' => $after];
    }

    public function has(string $key): bool
    {
        return isset($this->importers[$key]);
    }

    public function get(string $key): RecordImporter
    {
        if (! isset($this->importers[$key])) {
            throw new InvalidArgumentException("No importer is registered for [{$key}].");
        }

        $definition = $this->importers[$key]['importer'];
        $importer = $definition instanceof Closure ? $definition($this->container) : $this->container->make($definition);

        if (! $importer instanceof RecordImporter) {
            throw new LogicException("The importer registered for [{$key}] resolves to ".get_debug_type($importer).', which does not implement '.RecordImporter::class.'.');
        }

        if ($importer->key() !== $key) {
            throw new LogicException("The importer registered for [{$key}] reports the key [{$importer->key()}].");
        }

        return $importer;
    }

    /**
     * Registered entities, in registration order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->importers);
    }

    /**
     * The requested entities (every registered one when none is given) and what they depend on, each after its
     * dependencies and after the `after` entities that are in the same list. Only the requested chains are checked,
     * so an entity of a module that is not loaded breaks only imports that need it; an `after` entity is never added
     * to the list and never required.
     *
     * @param  list<string>  $keys
     * @return list<string>
     *
     * @throws LogicException on a cycle, or a dependency nobody registered, in a requested chain
     */
    public function ordered(array $keys = []): array
    {
        $requested = $keys === [] ? $this->keys() : $keys;
        $included = [];

        $collect = function (string $key, ?string $requiredBy) use (&$collect, &$included): void {
            if (isset($included[$key])) {
                return;
            }

            if (! $this->has($key)) {
                throw new LogicException($requiredBy === null ? "No importer is registered for [{$key}]." : "Import entity [{$requiredBy}] depends on [{$key}], which no module registers.");
            }

            $included[$key] = true;

            foreach ($this->importers[$key]['dependsOn'] as $dependency) {
                $collect($dependency, $key);
            }
        };

        foreach ($requested as $key) {
            $collect($key, null);
        }

        $ordered = [];
        $visiting = [];

        $visit = function (string $key) use (&$visit, &$ordered, &$visiting, $included): void {
            if (in_array($key, $ordered, true)) {
                return;
            }

            if (isset($visiting[$key])) {
                throw new LogicException('Import entities depend on each other in a cycle: '.implode(' → ', [...array_keys($visiting), $key]).'.');
            }

            $visiting[$key] = true;
            $after = array_filter($this->importers[$key]['after'], fn (string $entity): bool => isset($included[$entity]));

            foreach ([...$this->importers[$key]['dependsOn'], ...$after] as $predecessor) {
                $visit($predecessor);
            }

            unset($visiting[$key]);
            $ordered[] = $key;
        };

        foreach ($requested as $key) {
            $visit($key);
        }

        return $ordered;
    }
}
