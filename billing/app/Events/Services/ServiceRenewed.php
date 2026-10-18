<?php

namespace App\Events\Services;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class ServiceRenewed implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * @param  string  $nextDueDate  Y-m-d
     */
    public function __construct(
        public int $serviceId,
        public int $clientId,
        public string $nextDueDate,
    ) {}
}
