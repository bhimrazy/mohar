<?php

namespace App\Contracts\Integrations;

use App\Data\Integrations\IntegrationLogo;

/**
 * Capability (ADR 0012) for any integration driver (gateway, control panel, registrar) that ships its provider's
 * official logo, sourced from the provider's own merchant, developer or brand resources and used unmodified.
 * Screens fall back to the driver's text name when there is none.
 */
interface ProvidesLogo
{
    public function logo(): ?IntegrationLogo;
}
