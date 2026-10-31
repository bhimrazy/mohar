<?php

namespace App\Modules\PaymentsNepal;

use App\Modules\PaymentsNepal\Drivers\ConnectipsDriver;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use App\Support\Billing\GatewayDriverRegistry;
use App\Support\Modules\ModuleServiceProvider;

/**
 * Nepal payment gateway drivers (docs/product/payments-nepal.md): Khalti, eSewa and connectIPS
 * (payments-gateways-expansion.md §4) as hosted, lookup-confirmed drivers. The module only registers drivers with
 * the kernel's GatewayDriverRegistry; it holds no tables, routes or settings of its own (credentials live encrypted in
 * Billing's gateway configuration).
 */
class PaymentsNepalServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'payments_nepal';
    }

    protected function registerModule(): void
    {
        // One instance per process, as when drivers were registered as instances.
        $this->app->singleton(KhaltiDriver::class);
        $this->app->singleton(EsewaDriver::class);
        $this->app->singleton(ConnectipsDriver::class);
    }

    protected function bootModule(): void
    {
        $drivers = $this->app->make(GatewayDriverRegistry::class);
        $drivers->register(KhaltiDriver::KEY, KhaltiDriver::class);
        $drivers->register(EsewaDriver::KEY, EsewaDriver::class);
        $drivers->register(ConnectipsDriver::KEY, ConnectipsDriver::class);
    }
}
