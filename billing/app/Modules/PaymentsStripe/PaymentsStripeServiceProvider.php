<?php

namespace App\Modules\PaymentsStripe;

use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Support\Billing\GatewayDriverRegistry;
use App\Support\Modules\ModuleServiceProvider;

/**
 * Stripe gateway driver (docs/product/payments-stripe.md): Checkout for invoice payments, signed webhooks that
 * trigger a retrieve, refunds and disputes. The module only registers its driver with the kernel's GatewayDriverRegistry;
 * it holds no tables or routes (credentials live encrypted in Billing's gateway configuration).
 */
class PaymentsStripeServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'payments_stripe';
    }

    protected function registerModule(): void
    {
        // One instance per process, as when drivers were registered as instances.
        $this->app->singleton(StripeDriver::class);
    }

    protected function bootModule(): void
    {
        $drivers = $this->app->make(GatewayDriverRegistry::class);
        $drivers->register(StripeDriver::KEY, StripeDriver::class);
    }
}
