<?php

namespace App\Modules\Services\Drivers\Provisioning;

use App\Modules\Services\Models\Server;

/**
 * Optional capability: single sign-on into the control panel.
 */
interface SupportsSso
{
    /**
     * Short-lived login URL for the service owner, or null when unavailable.
     */
    public function serviceSsoUrl(ServiceContext $context): ?string;

    /**
     * Short-lived login URL for staff into the server's admin panel, or null when unavailable.
     */
    public function serverSsoUrl(Server $server): ?string;
}
