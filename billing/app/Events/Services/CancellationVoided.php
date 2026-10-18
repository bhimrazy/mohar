<?php

namespace App\Events\Services;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A cancellation request was withdrawn; the service renews again.
 */
final class CancellationVoided
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $serviceId,
        public int $clientId,
        public int $cancellationRequestId,
    ) {}
}
