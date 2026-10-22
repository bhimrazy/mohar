<?php

namespace App\Contracts\Migration;

use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportOutcome;
use App\Data\Migration\ImportRecord;

/**
 * Capability of a RecordImporter whose writes a run's undo can take back (ADR 0012, ADR 0019; client-import spec §5.7).
 *
 * Migration hands back what it stored for this entity of one record: $record is what was written, and $applied is
 * the importer's own outcome from upsert() (action, target, changes and the `before` snapshot it read under its lock).
 * revert() opens its own transaction, locks what it writes, and takes back exactly what run $context->runId wrote,
 * only while that is still safe.
 *
 * It returns ImportAction::Reverted when it took the write back; ImportAction::Skipped, with the reason in the issues,
 * when it kept it; and ImportAction::Unchanged when there was nothing of this run's to take back (never written, or
 * already reverted). A dry run writes nothing and returns what it would do.
 */
interface RevertsImport extends RecordImporter
{
    public function revert(ImportRecord $record, ImportOutcome $applied, ImportContext $context): ImportOutcome;
}
