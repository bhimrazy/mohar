<?php

namespace App\Contracts\Services;

use App\Data\Services\AddonDraft;
use App\Data\Services\ProvisionResult;
use App\Data\Services\ServiceDraft;

/**
 * Implemented by the Services module. Used by Orders.
 */
interface ServiceRegistry
{
    /**
     * Create a pending service and return its id.
     */
    public function createPending(ServiceDraft $draft): int;

    /**
     * Create a pending service addon and return its id.
     */
    public function createPendingAddon(AddonDraft $draft): int;

    /**
     * Run the provisioning "create" action for a pending service and mark it active on success.
     */
    public function provision(int $serviceId): ProvisionResult;

    /**
     * Cancel a pending service (order cancelled / fraud). Does not call the driver.
     */
    public function cancelPending(int $serviceId, string $status = 'cancelled'): void;
}
