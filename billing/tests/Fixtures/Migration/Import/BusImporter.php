<?php

namespace Tests\Fixtures\Migration\Import;

use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Probe for the record-importer Arch rule (architecture review of #527): an importer that queues a job through the
 * injected bus.
 */
final class BusImporter
{
    public function __construct(private Dispatcher $bus) {}

    public function upsert(object $job): void
    {
        $this->bus->dispatch($job);
    }
}
