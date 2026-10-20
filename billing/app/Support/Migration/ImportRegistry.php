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
 *
 * The key is the entity: the same string is ImportRecord::$entity and the id map's entity. register() resolves
 * nothing; get() builds the importer through the container and checks it reports its key.
 *
 * @api
 */
final class ImportRegistry
{
    private const KEY_PATTERN = '/^[a-z0-9_]{1,50}$/';

    /** @var array<string, array{importer: class-string<RecordImporter>|Closure(Container): RecordImporter, dependsOn: list<string>}> */
    private array $importers = [];

    public function __construct(private Container $container) {}

    /**
     * @param  class-string<RecordImporter>|Closure(Container): RecordImporter  $importer
     * @param  list<string>  $dependsOn  entities that must be imported first
     */
    public function register(string $key, string|Closure $importer, bool $replace = false, array $dependsOn = []): void
    {
        foreach ([$key, ...$dependsOn] as $name) {
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

        $this->importers[$key] = ['importer' => $importer, 'dependsOn' => $dependsOn];
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
     * dependencies. Only the requested chains are checked, so an entity of a module that is not loaded breaks only
     * imports that need it.
     *
     * @param  list<string>  $keys
     * @return list<string>
     *
     * @throws LogicException on a cycle, or a dependency nobody registered, in a requested chain
     */
    public function ordered(array $keys = []): array
    {
        $ordered = [];
        $visiting = [];

        $visit = function (string $key, ?string $requiredBy) use (&$visit, &$ordered, &$visiting): void {
            if (in_array($key, $ordered, true)) {
                return;
            }

            if (! $this->has($key)) {
                throw new LogicException($requiredBy === null ? "No importer is registered for [{$key}]." : "Import entity [{$requiredBy}] depends on [{$key}], which no module registers.");
            }

            if (isset($visiting[$key])) {
                throw new LogicException('Import entities depend on each other in a cycle: '.implode(' → ', [...array_keys($visiting), $key]).'.');
            }

            $visiting[$key] = true;

            foreach ($this->importers[$key]['dependsOn'] as $dependency) {
                $visit($dependency, $key);
            }

            unset($visiting[$key]);
            $ordered[] = $key;
        };

        foreach ($keys === [] ? $this->keys() : $keys as $key) {
            $visit($key, null);
        }

        return $ordered;
    }
}
