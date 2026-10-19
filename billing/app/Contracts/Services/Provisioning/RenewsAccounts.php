<?php

namespace App\Contracts\Services\Provisioning;

use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Support\Services\RetryableProvisioningException;

/**
 * Capability: extend a remote account that has its own expiry (licences, some VPS platforms) after the service is
 * renewed. The context's ServiceSnapshot::$nextDueDate is the new due date.
 */
interface RenewsAccounts
{
    /**
     * @throws RetryableProvisioningException
     */
    public function renew(ProvisioningContext $context): DriverResult;
}
