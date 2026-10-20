<?php

namespace App\Events\Migration;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An import run stopped and waits for staff; $reason is a fixed sentence without personal data.
 */
final class MigrationRunPaused implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $runId,
        public string $reason,
    ) {}
}
