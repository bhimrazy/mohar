<?php

namespace Tests\Fixtures\Extensions\AcmePanel;

use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * How an outside package ships a provisioning and a registrar driver: a plain service provider, no module base
 * class, no dependency on the Services or Domains module being loaded.
 */
final class AcmePanelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(ProvisioningDriverRegistry::class)->register('acme_panel', AcmePanelDriver::class);
        $this->app->make(RegistrarDriverRegistry::class)->register('acme_registrar', AcmeRegistrarDriver::class);
    }
}
