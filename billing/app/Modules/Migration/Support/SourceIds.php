<?php

namespace App\Modules\Migration\Support;

use App\Contracts\Migration\ImportIdResolver;
use App\Modules\Migration\Models\MigrationIdMap;

/**
 * The id map of one source, for importers that link to records imported before.
 */
final readonly class SourceIds implements ImportIdResolver
{
    public function __construct(private int $sourceId) {}

    public function targetId(string $entity, string $sourceId): ?int
    {
        $target = MigrationIdMap::query()->where('migration_source_id', $this->sourceId)->where('entity', $entity)->where('source_id', $sourceId)->value('target_id');

        return $target === null ? null : (int) $target;
    }
}
