<?php

namespace App\Modules\Migration\Support;

use App\Contracts\Migration\ImportIdResolver;
use App\Modules\Migration\Models\MigrationIdMap;

/**
 * The id map of one source, for importers that link to records imported before.
 */
final readonly class SourceIds implements ImportIdResolver
{
    /** Source ids per query: 1,002 bindings, far below the SQLite and PostgreSQL limits. */
    private const BATCH = 1000;

    public function __construct(private int $sourceId) {}

    public function targetId(string $entity, string $sourceId): ?int
    {
        $target = MigrationIdMap::query()->where('migration_source_id', $this->sourceId)->where('entity', $entity)->where('source_id', $sourceId)->value('target_id');

        return $target === null ? null : (int) $target;
    }

    public function targetIds(string $entity, array $sourceIds): array
    {
        $targets = [];

        // Served by the unique (migration_source_id, entity, source_id) index.
        foreach (array_chunk(array_values(array_unique($sourceIds)), self::BATCH) as $batch) {
            MigrationIdMap::query()->where('migration_source_id', $this->sourceId)->where('entity', $entity)->whereIn('source_id', $batch)
                ->pluck('target_id', 'source_id')
                ->each(function (mixed $target, int|string $source) use (&$targets): void {
                    $targets[$source] = (int) $target;
                });
        }

        return $targets;
    }
}
