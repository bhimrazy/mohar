<?php

namespace App\Events\Migration;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An import run stopped and waits for staff to continue it (ADR 0019), after commit. $reasonCode is a stable code
 * ("apply_failed"), never a sentence.
 */
final class MigrationRunPaused implements DomainEvent, ShouldDispatchAfterCommit
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $runId,
        public string $reasonCode,
    ) {}
}
