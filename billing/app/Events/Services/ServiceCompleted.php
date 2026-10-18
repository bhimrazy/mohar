<?php

namespace App\Events\Services;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Service reached its billing cycle limit and its last paid period ended.
 */
final class ServiceCompleted
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $serviceId,
        public int $clientId,
    ) {}
}
