<?php

namespace App\Modules\Core\Support;

use App\Contracts\Migration\ImportRegistry;
use App\Contracts\Migration\RecordImporter;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The record importers modules register, resolved lazily from the container.
 */
class InMemoryImportRegistry implements ImportRegistry
{
    /** @var array<string, array{importer: class-string<RecordImporter>, dependsOn: list<string>}> */
    private array $types = [];

    public function __construct(private Container $container) {}

    public function register(string $type, string $importer, array $dependsOn = []): void
    {
        $this->types[$type] = ['importer' => $importer, 'dependsOn' => $dependsOn];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function importer(string $type): RecordImporter
    {
        if (! $this->has($type)) {
            throw new LogicException("No importer is registered for [{$type}].");
        }

        return $this->container->make($this->types[$type]['importer']);
    }

    public function ordered(): array
    {
        $ordered = [];
        $visiting = [];

        $visit = function (string $type, ?string $requiredBy) use (&$visit, &$ordered, &$visiting): void {
            if (in_array($type, $ordered, true)) {
                return;
            }

            if (! $this->has($type)) {
                throw new LogicException("Import type [{$requiredBy}] depends on [{$type}], which no module registers.");
            }

            if (isset($visiting[$type])) {
                throw new LogicException('Import types depend on each other in a cycle: '.implode(' → ', [...array_keys($visiting), $type]).'.');
            }

            $visiting[$type] = true;

            foreach ($this->types[$type]['dependsOn'] as $dependency) {
                $visit($dependency, $type);
            }

            unset($visiting[$type]);
            $ordered[] = $type;
        };

        foreach (array_keys($this->types) as $type) {
            $visit($type, null);
        }

        return $ordered;
    }
}
