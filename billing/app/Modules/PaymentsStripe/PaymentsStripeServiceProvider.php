<?php

namespace App\Modules\PaymentsStripe;

use App\Contracts\Billing\GatewayRegistry;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Support\Modules\ModuleServiceProvider;

/**
 * Stripe gateway driver (docs/product/payments-stripe.md): Checkout for invoice payments, signed webhooks that
 * trigger a retrieve, refunds and disputes. The module only registers its driver with Billing's GatewayRegistry;
 * it holds no tables or routes (credentials live encrypted in Billing's gateway configuration).
 */
class PaymentsStripeServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'payments_stripe';
    }

    protected function bootModule(): void
    {
        // Fires for every new registry instance (also when Billing rebuilds it), and at once if one exists already.
        $this->callAfterResolving(GatewayRegistry::class, function (GatewayRegistry $registry): void {
            $registry->register($this->app->make(StripeDriver::class));
        });
    }
}
