<?php

namespace App\Events\Support;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Source tickets were merged into the target ticket.
 */
final class TicketMerged implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * @param  list<int>  $sourceIds
     */
    public function __construct(
        public int $targetId,
        public array $sourceIds,
    ) {}
}
