<?php

namespace App\Contracts\Services\Provisioning;

use App\Data\Services\Provisioning\ProvisioningContext;
use App\Data\Services\Provisioning\SsoLink;

/**
 * Capability: a one-time login link into the control panel as the service's own account (client "Log in to
 * panel", staff "Log in as client").
 */
interface ProvidesServiceSso
{
    public function serviceSsoLink(ProvisioningContext $context): SsoLink;
}
