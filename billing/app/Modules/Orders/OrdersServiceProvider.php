<?php

namespace App\Modules\Orders;

use App\Contracts\Billing\GatewayDirectory;
use App\Contracts\Billing\Invoicer;
use App\Contracts\Billing\TaxCalculator;
use App\Contracts\Orders\OrderNumbers;
use App\Contracts\Orders\PaymentGuard;
use App\Contracts\Orders\ShopperSession;
use App\Contracts\Services\ServiceRegistry;
use App\Modules\Orders\Boot\Automation;
use App\Modules\Orders\Boot\CurrencyUsage;
use App\Modules\Orders\Boot\Dashboard;
use App\Modules\Orders\Boot\Listeners;
use App\Modules\Orders\Boot\MergeFields;
use App\Modules\Orders\Boot\Middleware;
use App\Modules\Orders\Boot\Navigation;
use App\Modules\Orders\Boot\Permissions;
use App\Modules\Orders\Boot\RateLimiters;
use App\Modules\Orders\Boot\Search;
use App\Modules\Orders\Boot\Settings;
use App\Modules\Orders\Boot\Sitemap;
use App\Modules\Orders\Boot\Webhooks;
use App\Modules\Orders\Fraud\FraudScreening;
use App\Modules\Orders\Services\CartShopperSession;
use App\Modules\Orders\Services\OrderDirectory;
use App\Modules\Orders\Services\OrderPaymentGuard;
use App\Modules\Orders\Support\Demo\DemoBilling;
use App\Modules\Orders\Support\Demo\DemoServices;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Http\Request;

class OrdersServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'orders';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(FraudScreening::class);
        $this->app->bind(PaymentGuard::class, OrderPaymentGuard::class);
        $this->app->bind(OrderNumbers::class, OrderDirectory::class);
        $this->app->scoped(ShopperSession::class, CartShopperSession::class);
    }

    protected function bootModule(): void
    {
        $this->bindDemoContracts();

        foreach ([
            Webhooks::class, Middleware::class, CurrencyUsage::class, Listeners::class, RateLimiters::class,
            Navigation::class, Dashboard::class, Permissions::class, Settings::class, Search::class,
            MergeFields::class, Automation::class, Sitemap::class,
        ] as $boot) {
            $this->app->make($boot)($this->app);
        }
    }

    /**
     * Local-only stand-ins for modules that are not merged yet. Services and Billing contracts have no Core
     * default, so an unbound ServiceRegistry/Invoicer means the module is absent; once it is merged, its own
     * bindings win and these are never registered.
     */
    private function bindDemoContracts(): void
    {
        if (! $this->app->isLocal()) {
            return;
        }

        if (! $this->app->bound(ServiceRegistry::class)) {
            $this->app->singleton(ServiceRegistry::class, DemoServices::class);
        }

        if (! $this->app->bound(Invoicer::class)) {
            $this->app->singleton(DemoBilling::class);

            foreach ([Invoicer::class, TaxCalculator::class, GatewayDirectory::class] as $contract) {
                $this->app->singleton($contract, DemoBilling::class);
            }
        }
    }

    public static function throttleKey(Request $request): string
    {
        return $request->user() !== null ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip();
    }
}
