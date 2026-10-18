<?php

namespace App\Events\Services;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class ServiceCancellationRequested
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * @param  string  $type  immediate|end_of_period
     */
    public function __construct(
        public int $serviceId,
        public int $clientId,
        public string $type,
        public ?string $reason,
    ) {}
}
