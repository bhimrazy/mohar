<?php

namespace App\Events\Support;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A network incident was published.
 */
final class NetworkIncidentOpened
{
    use Dispatchable;

    public function __construct(
        public int $incidentId,
    ) {}
}
