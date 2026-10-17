<?php

namespace App\Modules\Support\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A network incident was resolved.
 *
 * Module-local until promoted to App\Events\Support by the shared-kernel owner (spec §12).
 */
final class NetworkIncidentResolved
{
    use Dispatchable;

    public function __construct(
        public int $incidentId,
    ) {}
}
