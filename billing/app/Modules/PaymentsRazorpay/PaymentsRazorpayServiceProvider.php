<?php

namespace App\Modules\PaymentsRazorpay;

use App\Contracts\Billing\GatewayRegistry;
use App\Modules\PaymentsRazorpay\Drivers\RazorpayDriver;
use App\Support\Modules\ModuleServiceProvider;

/**
 * Razorpay gateway driver for India (docs/product/payments-gateways-expansion.md §8): Payment Links settled by lookup,
 * signed webhooks that trigger a lookup, full and partial refunds. The module only registers its driver with Billing's
 * GatewayRegistry; it holds no tables or routes (credentials live encrypted in Billing's gateway configuration).
 */
class PaymentsRazorpayServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'payments_razorpay';
    }

    protected function bootModule(): void
    {
        // Fires for every new registry instance (also when Billing rebuilds it), and at once if one exists already.
        $this->callAfterResolving(GatewayRegistry::class, function (GatewayRegistry $registry): void {
            $registry->register($this->app->make(RazorpayDriver::class));
        });
    }
}
