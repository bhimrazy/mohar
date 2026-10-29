<?php

namespace App\Contracts\Migration;

use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportRecord;

/**
 * Capability of a RecordImporter that loads what a chunk of records needs in one go (ADR 0012, ADR 0019), so checking
 * 50,000 rows costs one query per lookup per chunk instead of one per row.
 *
 * Per chunk, Migration replaces mapped values (DeclaresValueMaps), takes a new instance from ImportRegistry::get(),
 * and calls prepare() once with every record of the chunk, then matchExisting() (MatchesExisting), then validate()
 * or upsert() per record. Prepared state lives on that instance only and covers only those records, so the importer
 * must be registered so that get() builds a new instance on each call: a class name not bound as a singleton, or a
 * closure that constructs one. validate() and upsert() still work on a record that was not prepared (they look it up
 * themselves). prepare() writes nothing, also when the run applies: upsert() re-reads under its lock what it writes.
 */
interface PreparesChunk extends RecordImporter
{
    /**
     * @param  list<ImportRecord>  $records
     */
    public function prepare(array $records, ImportContext $context): void;
}
