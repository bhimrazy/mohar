<?php

namespace App\Contracts\Billing;

use App\Data\Billing\GatewayLogo;

/**
 * Opt-in for drivers that ship their provider's official logo (sourced from the provider's own merchant, developer
 * or brand resources, used unmodified). Screens fall back to the gateway's text name when there is none.
 */
interface ProvidesLogo
{
    public function logo(): ?GatewayLogo;
}
