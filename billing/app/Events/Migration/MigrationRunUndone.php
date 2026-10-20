<?php

namespace App\Events\Migration;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An applied import run was undone: $reverted records reversed, $kept kept with a reason.
 */
final class MigrationRunUndone implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $runId,
        public int $reverted,
        public int $kept,
    ) {}
}
