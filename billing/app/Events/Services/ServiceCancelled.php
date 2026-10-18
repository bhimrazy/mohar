<?php

namespace App\Events\Services;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Service ended as cancelled (cancellation request processed or terminate-at-period-end).
 */
final class ServiceCancelled
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $serviceId,
        public int $clientId,
    ) {}
}
