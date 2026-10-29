<?php

namespace App\Contracts\Migration;

/**
 * Resolves a source record's id to the id of the record imported from it in the run's source (the id map).
 *
 * Service contract (ADR 0010): Migration implements it, importers consume it.
 */
interface ImportIdResolver
{
    /**
     * @param  string  $entity  the importer's registry key, e.g. "client"
     */
    public function targetId(string $entity, string $sourceId): ?int;

    /**
     * targetId() for many source ids with a fixed number of queries, for PreparesChunk::prepare().
     *
     * @param  string  $entity  the importer's registry key, e.g. "client"
     * @param  list<string>  $sourceIds
     * @return array<int|string, int> target id keyed by source id; ids not in the map are left out. PHP stores a
     *                                numeric source id ("1001") as an int key: look ids up by the source id
     *                                (`$targets[$record->sourceId] ?? null`), never by comparing keys strictly.
     */
    public function targetIds(string $entity, array $sourceIds): array;
}
