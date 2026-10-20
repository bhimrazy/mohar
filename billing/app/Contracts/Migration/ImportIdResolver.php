<?php

namespace App\Contracts\Migration;

/**
 * Resolves a source record's id to the id of the record imported from it in this run's source (the id map).
 */
interface ImportIdResolver
{
    public function targetId(string $type, string $sourceId): ?int;
}
