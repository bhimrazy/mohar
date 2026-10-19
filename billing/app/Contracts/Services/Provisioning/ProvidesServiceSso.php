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
    /**
     * Panel pages a link can open directly, as shortcut buttons: target => label (e.g. `email` => "Email accounts").
     * Static; an empty list means the panel home only.
     *
     * @return array<string, string>
     */
    public function ssoTargets(): array;

    /**
     * @param  string|null  $target  one of ssoTargets(); null or an unknown target opens the panel home
     */
    public function serviceSsoLink(ProvisioningContext $context, ?string $target = null): SsoLink;
}
