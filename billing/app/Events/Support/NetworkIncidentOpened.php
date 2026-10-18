<?php

namespace App\Events\Support;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A network incident was published.
 */
final class NetworkIncidentOpened
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $incidentId,
    ) {}
}
