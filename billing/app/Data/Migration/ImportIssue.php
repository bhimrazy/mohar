<?php

namespace App\Data\Migration;

use App\Enums\ImportSeverity;

/**
 * A problem found with a record. The context holds row-safe parameters (field names, row numbers), never values.
 */
final readonly class ImportIssue
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public ImportSeverity $severity,
        public string $code,
        public string $message,
        public array $context = [],
    ) {}
}
