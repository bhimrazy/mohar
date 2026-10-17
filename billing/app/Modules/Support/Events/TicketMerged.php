<?php

namespace App\Modules\Support\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Source tickets were merged into the target ticket.
 *
 * Module-local until promoted to App\Events\Support by the shared-kernel owner (spec §12).
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
