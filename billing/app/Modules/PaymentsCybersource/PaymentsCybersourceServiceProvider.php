<?php

namespace App\Modules\PaymentsCybersource;

use App\Modules\PaymentsCybersource\Drivers\CybersourceDriver;
use App\Support\Billing\GatewayDriverRegistry;
use App\Support\Modules\ModuleServiceProvider;

/**
 * Card payments through Cybersource Secure Acceptance Hosted Checkout (NIC Asia Bank), confirmed through the
 * Cybersource REST API (docs/product/payments-gateways-expansion.md §6.2). The module only registers its driver with
 * the kernel's GatewayDriverRegistry; it holds no tables, routes or settings of its own.
 */
class PaymentsCybersourceServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'payments_cybersource';
    }

    protected function registerModule(): void
    {
        // One instance per process, as when drivers were registered as instances.
        $this->app->singleton(CybersourceDriver::class);
    }

    protected function bootModule(): void
    {
        $drivers = $this->app->make(GatewayDriverRegistry::class);
        $drivers->register(CybersourceDriver::KEY, CybersourceDriver::class);
    }
}
