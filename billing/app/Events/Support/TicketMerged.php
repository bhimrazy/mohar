<?php

namespace App\Events\Support;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Source tickets were merged into the target ticket.
 */
final class TicketMerged
{
    use Dispatchable;

    /**
     * @param  list<int>  $sourceIds
     */
    public function __construct(
        public int $targetId,
        public array $sourceIds,
    ) {}
}
