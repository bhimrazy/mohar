<?php

namespace App\Contracts\Migration;

use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportIssue;
use App\Data\Migration\ImportOutcome;
use App\Data\Migration\ImportRecord;

/**
 * Writes one imported record type into the module that owns it (migration spec §12, client-import spec §16 C1). The
 * importer knows nothing about the source system: connectors translate their data into our vocabulary first, so one
 * importer serves CSV files and every API connector.
 */
interface RecordImporter
{
    /**
     * Laravel validation rules for ImportRecord::$attributes (and children.*). They must not query the database, so
     * a check can run them on thousands of rows with a fixed number of queries.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Checks beyond rules() (references, allowed values). Writes nothing.
     *
     * @return list<ImportIssue>
     */
    public function validate(ImportRecord $record, ImportContext $context): array;

    /**
     * Creates the target (when $existingTargetId is null) or updates it. Idempotent, writes quietly, dispatches no
     * domain event, sends no mail, calls no driver and recalculates no money. Runs inside the caller's transaction.
     */
    public function upsert(ImportRecord $record, ImportContext $context, ?int $existingTargetId): ImportOutcome;

    /**
     * Runs once per run after every record of the type is written (sequences, caches, reconciliation).
     *
     * @return list<ImportIssue>
     */
    public function finalize(ImportContext $context): array;

    /**
     * Applies automation baselines to imported records at cutover. Idempotent; returns the number affected.
     */
    public function cutover(ImportContext $context): int;
}
