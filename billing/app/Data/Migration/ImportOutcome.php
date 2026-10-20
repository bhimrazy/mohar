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
     */
    public function __construct(
        public ?int $targetId,
        public ImportAction $action,
        public array $issues = [],
        public array $changes = [],
    ) {}
}
