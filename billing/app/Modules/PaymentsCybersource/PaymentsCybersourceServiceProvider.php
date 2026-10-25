<?php

namespace App\Modules\PaymentsCybersource;

use App\Contracts\Billing\GatewayRegistry;
use App\Modules\PaymentsCybersource\Drivers\CybersourceDriver;
use App\Support\Modules\ModuleServiceProvider;

/**
 * Card payments through Cybersource Secure Acceptance Hosted Checkout (NIC Asia Bank), confirmed through the
 * Cybersource REST API (docs/product/payments-gateways-expansion.md §6.2). The module only registers its driver with
 * Billing's GatewayRegistry; it holds no tables, routes or settings of its own.
 */
class PaymentsCybersourceServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'payments_cybersource';
    }

    protected function bootModule(): void
    {
        // Fires for every new registry instance (also when Billing rebuilds it), and at once if one exists already.
        $this->callAfterResolving(GatewayRegistry::class, function (GatewayRegistry $registry): void {
            $registry->register($this->app->make(CybersourceDriver::class));
        });
    }
}
