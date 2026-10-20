<?php

namespace App\Events\Migration;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An import run finished applying (ADR 0019). $sourceSystem is the kind of source ("csv", "whmcs_api"); $failed says
 * the run stopped instead of finishing. Dispatched once per run; no per-record event is ever dispatched.
 */
final class MigrationRunCompleted implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * @param  array<string, array{created: int, updated: int, unchanged: int, skipped: int, failed: int}>  $counts  by entity (importer key)
     */
    public function __construct(
        public int $runId,
        public string $sourceSystem,
        public bool $failed,
        public array $counts,
    ) {}
}
