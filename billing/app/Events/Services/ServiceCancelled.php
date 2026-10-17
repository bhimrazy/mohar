<?php

namespace App\Events\Services;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Service ended as cancelled (cancellation request processed or terminate-at-period-end).
 */
final class ServiceCancelled
{
    use Dispatchable;

    public function __construct(
        public int $serviceId,
        public int $clientId,
    ) {}
}
