<?php

namespace App\Modules\PaymentsRazorpay;

use App\Modules\PaymentsRazorpay\Drivers\RazorpayDriver;
use App\Support\Billing\GatewayDriverRegistry;
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

    protected function registerModule(): void
    {
        // One instance per process, as when drivers were registered as instances.
        $this->app->singleton(RazorpayDriver::class);
    }

    protected function bootModule(): void
    {
        $drivers = $this->app->make(GatewayDriverRegistry::class);
        $drivers->register(RazorpayDriver::KEY, RazorpayDriver::class);
    }
}
