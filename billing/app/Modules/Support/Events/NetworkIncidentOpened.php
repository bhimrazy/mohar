<?php

namespace App\Modules\Support\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A network incident was published.
 *
 * Module-local until promoted to App\Events\Support by the shared-kernel owner (spec §12).
 */
final class NetworkIncidentOpened
{
    use Dispatchable;

    public function __construct(
        public int $incidentId,
    ) {}
}
