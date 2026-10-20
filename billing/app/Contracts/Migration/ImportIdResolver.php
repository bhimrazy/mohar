<?php

namespace App\Contracts\Migration;

/**
 * Resolves a source record's id to the id of the record imported from it in the run's source (the id map).
 */
interface ImportIdResolver
{
    /**
     * @param  string  $entity  the importer's registry key, e.g. "client"
     */
    public function targetId(string $entity, string $sourceId): ?int;
}
