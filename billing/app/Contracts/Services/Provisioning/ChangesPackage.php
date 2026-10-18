<?php

namespace App\Contracts\Services\Provisioning;

use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\PreviousPackage;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Support\Services\RetryableProvisioningException;

/**
 * Capability: apply the service's current product and options to the remote account (upgrade, downgrade).
 */
interface ChangesPackage
{
    /**
     * @param  ProvisioningContext  $context  the service as it is now, after the change
     * @param  PreviousPackage  $previous  what it was before
     *
     * @throws RetryableProvisioningException
     */
    public function changePackage(ProvisioningContext $context, PreviousPackage $previous): DriverResult;
}
