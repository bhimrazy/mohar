<?php

namespace App\Events\Support;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A network incident received a status update.
 */
final class NetworkIncidentUpdated
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $incidentId,
        public string $status,
    ) {}
}
