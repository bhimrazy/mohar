<?php

namespace App\Events\Migration;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An import run finished applying (ADR 0019): dispatched once, on the applying → completed transition, after commit.
 * A run that stops dispatches MigrationRunPaused instead, never this. $sourceSystem is the kind of source ("csv",
 * "whmcs_api"). No per-record event is ever dispatched.
 */
final class MigrationRunCompleted implements DomainEvent, ShouldDispatchAfterCommit
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * @param  array<string, array{created: int, updated: int, unchanged: int, skipped: int, failed: int}>  $counts  keyed by entity (importer key)
     */
    public function __construct(
        public int $runId,
        public string $sourceSystem,
        public array $counts,
    ) {}
}
