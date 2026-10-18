<?php

namespace App\Events\Support;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A network incident was resolved.
 */
final class NetworkIncidentResolved
{
    use Dispatchable;

    public function __construct(
        public int $incidentId,
    ) {}
}
