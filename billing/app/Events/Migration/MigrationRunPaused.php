<?php

namespace App\Events\Migration;

use App\Contracts\Events\DomainEvent;
use App\Enums\ImportStopReason;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An import run stopped and waits for staff to continue it (ADR 0019), after commit. $reasonCode is an open
 * enum (ADR 0011): consumers need a default branch for reasons added later.
 */
final class MigrationRunPaused implements DomainEvent, ShouldDispatchAfterCommit
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $runId,
        public ImportStopReason $reasonCode,
    ) {}
}
