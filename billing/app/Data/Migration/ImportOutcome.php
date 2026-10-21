<?php

namespace App\Data\Migration;

use App\Enums\ImportAction;

/**
 * What an importer did with one record.
 */
final readonly class ImportOutcome
{
    /**
     * @param  list<ImportIssue>  $issues
     * @param  list<string>  $changes  names of the fields an update changed (or, in a dry run, would change)
     * @param  array<string, mixed>|null  $before  the changed fields' previous values, read by the importer under its
     *                                             own lock (ADR 0019), so undo never restores over a later edit; null
     *                                             when nothing changed or the importer keeps no snapshot
     */
    public function __construct(
        public ?int $targetId,
        public ImportAction $action,
        public array $issues = [],
        public array $changes = [],
        public ?array $before = null,
    ) {}
}
