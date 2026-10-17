<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

final class ClientMerged
{
    use Dispatchable;

    /**
     * Dispatched after $sourceClientId was merged into $targetClientId (source is closed).
     * Modules holding denormalised client data (balances, counters, caches) must reconcile on this event.
     */
    public function __construct(
        public int $sourceClientId,
        public int $targetClientId,
    ) {}
}
