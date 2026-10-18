<?php

namespace App\Events\Services;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class ServiceUpgraded
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $serviceId,
        public int $clientId,
        public int $fromProductId,
        public int $toProductId,
    ) {}
}
