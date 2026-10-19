<?php

namespace App\Contracts\Services\Provisioning;

use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Support\Services\RetryableProvisioningException;

/**
 * Capability: set a new password on the remote account. The host stores it on the service after success.
 */
interface ChangesPassword
{
    /**
     * @throws RetryableProvisioningException
     */
    public function changePassword(ProvisioningContext $context, #[\SensitiveParameter] string $newPassword): DriverResult;
}
