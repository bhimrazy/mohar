<?php

namespace App\Events\Migration;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An import run finished applying (completed or failed). Counts are per outcome.
 */
final class MigrationRunCompleted implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $runId,
        public string $sourceKind,
        public string $entity,
        public string $status,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $skipped,
        public int $failed,
    ) {}
}
