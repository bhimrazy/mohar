<?php

namespace App\Contracts\Migration;

use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportIssue;
use App\Data\Migration\ImportOutcome;
use App\Data\Migration\ImportRecord;

/**
 * Writes one imported entity into the module that owns it (migration spec §12, client-import spec §16 C1). The
 * importer knows nothing about the source system: connectors translate their data into our vocabulary first, so one
 * importer serves CSV files and every API connector. Registered with App\Support\Migration\ImportRegistry.
 *
 * Provider contract (ADR 0010): never add a method; growth is through capability interfaces (ADR 0012).
 *
 * Importers write quietly: no domain event, mail or notification, no driver call, no money recalculated (Arch rule).
 * When $context->dryRun is true, upsert() writes nothing and returns the outcome it would produce (action, changes,
 * issues); callers never rely on a rollback, which would not undo sequences or stored files.
 */
interface RecordImporter
{
    /**
     * The entity this importer writes: the key it is registered under (ImportRecord::$entity).
     */
    public function key(): string;

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
     * Creates the target (when $existingTargetId is null) or updates it. Idempotent; runs inside the caller's
     * transaction.
     */
    public function upsert(ImportRecord $record, ImportContext $context, ?int $existingTargetId): ImportOutcome;

    /**
     * Runs once per run after every record of the entity is written (sequences, caches, reconciliation).
     *
     * @return list<ImportIssue>
     */
    public function finalize(ImportContext $context): array;

    /**
     * Applies automation baselines to imported records at cutover. Idempotent; returns the number affected.
     */
    public function cutover(ImportContext $context): int;
}
