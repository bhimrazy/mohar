<?php

namespace App\Events\Services;

use Illuminate\Foundation\Events\Dispatchable;

final class ServiceRenewed
{
    use Dispatchable;

    /**
     * @param  string  $nextDueDate  Y-m-d
     */
    public function __construct(
        public int $serviceId,
        public int $clientId,
        public string $nextDueDate,
    ) {}
}
