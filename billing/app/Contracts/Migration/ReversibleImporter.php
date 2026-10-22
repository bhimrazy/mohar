<?php

namespace App\Contracts\Migration;

use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportOutcome;

/**
 * Capability of a RecordImporter (ADR 0012) whose writes an import's undo can reverse (client-import spec §5.7).
 * reverse() takes back exactly what run $context->runId wrote for the target, under the importer's own lock, and only
 * while that is still safe; otherwise it writes nothing and says why in the outcome's issues (ImportAction::Skipped).
 * Idempotent: reversing twice, or reversing what was never written, is ImportAction::Unchanged. A dry run writes
 * nothing.
 */
interface ReversibleImporter extends RecordImporter
{
    public function reverse(int $targetId, ImportContext $context): ImportOutcome;
}
