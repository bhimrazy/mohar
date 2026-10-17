<?php

namespace App\Events\Services;

use Illuminate\Foundation\Events\Dispatchable;

final class ServiceActivated
{
    use Dispatchable;

    public function __construct(
        public int $serviceId,
        public int $clientId,
    ) {}
}
