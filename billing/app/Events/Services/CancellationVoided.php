<?php

namespace App\Events\Services;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A cancellation request was withdrawn; the service renews again.
 */
final class CancellationVoided
{
    use Dispatchable;

    public function __construct(
        public int $serviceId,
        public int $clientId,
        public int $cancellationRequestId,
    ) {}
}
