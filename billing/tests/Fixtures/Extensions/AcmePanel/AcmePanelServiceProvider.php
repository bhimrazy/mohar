<?php

namespace Tests\Fixtures\Extensions\AcmePanel;

use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * How an outside package ships a provisioning driver: a plain service provider, no module base class, no
 * dependency on the Services module being loaded.
 */
final class AcmePanelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(ProvisioningDriverRegistry::class)->register('acme_panel', AcmePanelDriver::class);
    }
}
