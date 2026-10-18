<?php

namespace App\Modules\PaymentsNepal;

use App\Contracts\Billing\GatewayRegistry;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use App\Support\Modules\ModuleServiceProvider;

/**
 * Nepal payment gateway drivers (docs/product/payments-nepal.md): Khalti and eSewa as hosted, lookup-confirmed
 * drivers. The module only registers drivers with Billing's GatewayRegistry; it holds no tables, routes or
 * settings of its own (credentials live encrypted in Billing's gateway configuration).
 */
class PaymentsNepalServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'payments_nepal';
    }

    protected function bootModule(): void
    {
        // Fires for every new registry instance (also when Billing rebuilds it), and at once if one exists already.
        $this->callAfterResolving(GatewayRegistry::class, function (GatewayRegistry $registry): void {
            $registry->register($this->app->make(KhaltiDriver::class));
        });
    }
}
