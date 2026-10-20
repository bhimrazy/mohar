<?php

namespace App\Contracts\Migration;

/**
 * The record importers modules register in bootModule(), keyed by type ("core.client"), with the types each depends
 * on. Implemented by Core in memory.
 */
interface ImportRegistry
{
    /**
     * @param  class-string<RecordImporter>  $importer
     * @param  list<string>  $dependsOn
     */
    public function register(string $type, string $importer, array $dependsOn = []): void;

    public function has(string $type): bool;

    public function importer(string $type): RecordImporter;

    /**
     * Every registered type, each after the types it depends on.
     *
     * @return list<string>
     *
     * @throws \LogicException on a dependency cycle or a dependency nobody registered
     */
    public function ordered(): array;
}
