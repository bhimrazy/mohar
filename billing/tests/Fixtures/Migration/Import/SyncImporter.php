<?php

namespace Tests\Fixtures\Migration\Import;

/**
 * Probe for the record-importer Arch rule (architecture review of #527): an importer that runs a job in-process.
 */
final class SyncImporter
{
    public function upsert(object $job): void
    {
        dispatch_sync($job);
    }
}
