<?php

namespace App\Contracts\Migration;

use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportRecord;

/**
 * Capability of a RecordImporter that loads what a chunk of records needs in one go (ADR 0012, ADR 0019), so checking
 * 50,000 rows costs one query per lookup per chunk instead of one per row.
 *
 * Migration takes a fresh instance from ImportRegistry::get() for each chunk and calls prepare() once, with every
 * record of the chunk, before validate() or upsert() runs on any of them. Prepared state lives on that instance only
 * and covers only those records; validate() and upsert() still work on a record that was not prepared (they look it
 * up themselves). prepare() writes nothing, also when the run applies: upsert() re-reads under its lock what it
 * writes.
 */
interface PreparesChunk extends RecordImporter
{
    /**
     * @param  list<ImportRecord>  $records
     */
    public function prepare(array $records, ImportContext $context): void;
}
