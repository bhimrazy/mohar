<?php

namespace App\Data\Services\Provisioning;

use App\Enums\BillingCycle;

/**
 * The service a driver acts on, as the host read it just before the call.
 */
final readonly class ServiceSnapshot
{
    /**
     * @param  string  $status  the service status value (pending, active, suspended, terminated, cancelled, ...)
     * @param  list<string>  $assignedIps
     * @param  array<string, mixed>  $driverData  what the driver returned as `driver_data` earlier (remote ids, ...)
     * @param  string|null  $nextDueDate  Y-m-d
     */
    public function __construct(
        public int $id,
        public int $clientId,
        public int $productId,
        public string $status,
        public ?string $domain = null,
        public ?string $username = null,
        #[\SensitiveParameter]
        public ?string $password = null,
        public ?string $dedicatedIp = null,
        public array $assignedIps = [],
        public array $driverData = [],
        public ?BillingCycle $billingCycle = null,
        public ?string $nextDueDate = null,
    ) {}
}
