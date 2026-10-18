<?php

namespace App\Events\Services;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A cancellation request was withdrawn; the service renews again.
 */
final class CancellationVoided implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $serviceId,
        public int $clientId,
        public int $cancellationRequestId,
    ) {}
}
