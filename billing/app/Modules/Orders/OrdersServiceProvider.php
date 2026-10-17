<?php

namespace App\Modules\Orders;

use App\Contracts\Billing\Invoicer;
use App\Contracts\Billing\TaxCalculator;
use App\Contracts\Catalog\PriceBook;
use App\Contracts\Domains\DomainPricing;
use App\Contracts\Domains\DomainRegistry;
use App\Contracts\Notifications\MergeFieldRegistry;
use App\Contracts\Services\ServiceRegistry;
use App\Events\Billing\InvoicePaid;
use App\Events\Domains\DomainRegistered;
use App\Events\Domains\DomainTransferred;
use App\Events\Services\ServiceActivated;
use App\Models\User;
use App\Modules\Orders\Actions\CancelStalePendingOrders;
use App\Modules\Orders\Actions\PruneExpiredCarts;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Fraud\FraudScreening;
use App\Modules\Orders\Http\Middleware\CaptureReferralAndPromo;
use App\Modules\Orders\Listeners\AcceptPaidOrder;
use App\Modules\Orders\Listeners\MergeCartOnLogin;
use App\Modules\Orders\Listeners\TrackItemActivation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\CompanyClock;
use App\Modules\Orders\Support\Demo\DemoBilling;
use App\Modules\Orders\Support\Demo\DemoCatalog;
use App\Modules\Orders\Support\Demo\DemoDomains;
use App\Modules\Orders\Support\Demo\DemoServices;
use App\Support\Automation\Automation;
use App\Support\Dashboard\Dashboard;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Event;

class OrdersServiceProvider extends ModuleServiceProvider
{
    /**
     * Local-only stand-ins for sibling contracts, bound only while the real module is absent.
     *
     * @var array<class-string, class-string>
     */
    private const DEMO_BINDINGS = [
        PriceBook::class => DemoCatalog::class,
        DomainPricing::class => DemoDomains::class,
        DomainRegistry::class => DemoDomains::class,
        ServiceRegistry::class => DemoServices::class,
        Invoicer::class => DemoBilling::class,
        TaxCalculator::class => DemoBilling::class,
    ];

    public function moduleKey(): string
    {
        return 'orders';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(FraudScreening::class);
    }

    protected function bootModule(): void
    {
        $this->bindDemoContracts();

        $this->app->make(HttpKernel::class)->appendMiddlewareToGroup('web', CaptureReferralAndPromo::class);

        Event::listen(Login::class, MergeCartOnLogin::class);
        Event::listen(ServiceActivated::class, TrackItemActivation::class);
        Event::listen(DomainRegistered::class, TrackItemActivation::class);
        Event::listen(DomainTransferred::class, TrackItemActivation::class);
        Event::listen(InvoicePaid::class, AcceptPaidOrder::class);

        $this->registerNavigation();
        $this->registerPermissionsAndSettings();
        $this->registerSearchAndMergeFields();
        $this->registerAutomation();
    }

    /**
     * Demo catalog/billing for local development and screenshots before sibling modules are merged.
     * Runs at boot so every module's register() has had the chance to bind the real implementation.
     */
    private function bindDemoContracts(): void
    {
        if (! $this->app->isLocal()) {
            return;
        }

        foreach (self::DEMO_BINDINGS as $contract => $demo) {
            if (! $this->app->bound($contract)) {
                $this->app->singleton($demo);
                $this->app->singleton($contract, $demo);
            }
        }
    }

    private function registerNavigation(): void
    {
        $navigation = $this->app->make(Navigation::class);
        $navigation->add('admin', new NavItem('Orders', 'admin.orders.index', 'ShoppingCart', 'Orders', 10, 'orders.orders.view', 'admin.orders.*'));
        $navigation->add('admin', new NavItem('Promotions', 'admin.promotions.index', 'Percent', 'Orders', 20, 'orders.promotions.manage', 'admin.promotions.*'));
        $navigation->add('client', new NavItem('Order history', 'client.orders.index', 'Receipt', 'Orders', 10, null, 'client.orders.*'));
        $navigation->add('client', new NavItem('Store', 'store.index', 'ShoppingCart', 'Orders', 20));
        $navigation->add('admin-client', new NavItem('Orders', 'admin.orders.index', 'ShoppingCart', order: 20, permission: 'orders.orders.view'));

        $dashboard = $this->app->make(Dashboard::class);
        $dashboard->card('admin', 'orders.pending', fn (?User $user): array => [
            'label' => 'Pending orders',
            'value' => Order::query()->where('status', OrderStatus::Pending)->count(),
            'href' => route('admin.orders.index', ['status' => OrderStatus::Pending->value]),
            'icon' => 'ShoppingCart',
        ], 'orders.orders.view', 10);
        $dashboard->card('admin', 'orders.today', fn (?User $user): array => [
            'label' => 'Orders today',
            'value' => Order::query()->where('created_at', '>=', $this->app->make(CompanyClock::class)->now()->startOfDay()->utc())->count(),
            'href' => route('admin.orders.index', ['from' => $this->app->make(CompanyClock::class)->now()->toDateString()]),
            'icon' => 'TrendingUp',
        ], 'orders.orders.view', 15);
    }

    private function registerPermissionsAndSettings(): void
    {
        $this->app->make(PermissionRegistry::class)->register('Orders', [
            'orders.orders.view' => 'View orders',
            'orders.orders.create' => 'Place orders for clients',
            'orders.orders.accept' => 'Accept orders',
            'orders.orders.cancel' => 'Cancel orders',
            'orders.orders.mark_fraud' => 'Mark orders as fraud, set back to pending, re-run fraud checks',
            'orders.orders.delete' => 'Delete orders',
            'orders.promotions.manage' => 'Manage promotions',
            'orders.fraud.configure' => 'Configure order and fraud settings',
        ]);

        $this->app->make(SettingsRegistry::class)->section('orders', 'Orders', [
            new SettingDefinition('orders.require_tos', 'Require terms of service acceptance at checkout', 'boolean'),
            new SettingDefinition('orders.tos_url', 'Terms of service URL', 'string', rules: ['nullable', 'url', 'max:255']),
            new SettingDefinition('orders.checkout_redirect', 'After checkout, send customers to', 'select', options: ['complete_page' => 'Order confirmation page', 'invoice' => 'Invoice', 'gateway' => 'Payment gateway']),
            new SettingDefinition('orders.initial_invoice_grace_days', 'Days until the first invoice is due', 'integer', rules: ['min:0', 'max:90']),
            new SettingDefinition('orders.cart_ttl_days', 'Days a cart is kept', 'integer', rules: ['min:1', 'max:365']),
            new SettingDefinition('orders.allow_checkout_notes', 'Let customers add notes at checkout', 'boolean'),
            new SettingDefinition('orders.block_duplicate_domains', 'Block ordering hosting for a domain already in use', 'boolean'),
            new SettingDefinition('orders.transfer_requires_epp', 'Require an EPP code for domain transfers', 'boolean'),
            new SettingDefinition('orders.domains_taxable', 'Charge tax on domains', 'boolean'),
            new SettingDefinition('orders.auto_accept_on_payment', 'Accept orders automatically when paid and the fraud check passed', 'boolean'),
            new SettingDefinition('orders.auto_provision_only_existing_clients', 'Only auto-provision for clients with an active service', 'boolean'),
            new SettingDefinition('orders.auto_cancel_unpaid_days', 'Cancel pending unpaid orders after N days (0 = never)', 'integer', rules: ['min:0', 'max:365']),
            new SettingDefinition('orders.suppress_invoice_email_on_order', 'Do not email the invoice for new orders', 'boolean'),
            new SettingDefinition('orders.fraud.driver', 'Fraud check provider', 'select', options: ['none' => 'None', 'rules' => 'Built-in rules']),
            new SettingDefinition('orders.fraud.max_pending_orders', 'Maximum pending orders per client', 'integer', rules: ['min:0']),
            new SettingDefinition('orders.fraud.max_orders_per_ip', 'Maximum orders per IP address in 24 hours', 'integer', rules: ['min:0']),
            new SettingDefinition('orders.fraud.banned_email_domains', 'Banned email domains', 'text', 'Comma separated, e.g. mailinator.com'),
            new SettingDefinition('orders.fraud.skip_for_existing_clients', 'Skip fraud checks for clients with an active service', 'boolean'),
            new SettingDefinition('orders.fraud.fail_open', 'Treat fraud provider errors as "review" instead of "fail"', 'boolean'),
            new SettingDefinition('orders.fraud.timeout_ms', 'Fraud check timeout (ms)', 'integer', rules: ['min:100', 'max:60000']),
        ], 'orders.fraud.configure');
    }

    private function registerSearchAndMergeFields(): void
    {
        $this->app->make(GlobalSearch::class)->provider('Orders', fn (string $term): array => array_values(Order::query()
            ->with('client')
            ->whereLike('number', '%'.$term.'%')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Order $order): array => [
                'title' => "Order #{$order->number}",
                'subtitle' => $order->client->display_name.' · '.$order->status->value,
                'href' => route('admin.orders.show', $order),
            ])
            ->all()), 'orders.orders.view');

        $this->app->make(MergeFieldRegistry::class)->register('order', function (int $id): array {
            $order = Order::query()->with('items')->findOrFail($id);

            return [
                'client_id' => $order->client_id,
                'id' => $order->id,
                'number' => $order->number,
                'date' => $order->created_at?->toDateString(),
                'status' => $order->status->value,
                'currency' => $order->currency,
                'total' => $order->total,
                'payment_method' => $order->payment_gateway,
                'invoice_id' => $order->invoice_id,
                'notes' => $order->notes,
                'items' => $order->items->map(fn (OrderItem $item): array => [
                    'description' => $item->description,
                    'amount' => $item->first_amount + $item->setup_fee - $item->discount_amount,
                    'cycle' => $item->cycle?->value,
                ])->values()->all(),
            ];
        });
    }

    private function registerAutomation(): void
    {
        $automation = $this->app->make(Automation::class);
        $automation->task('orders.cancel_stale_pending', Automation::CANCELLATIONS, fn (CarbonImmutable $today): int => $this->app->make(CancelStalePendingOrders::class)->handle($today));
        $automation->task('orders.prune_carts', Automation::HOUSEKEEPING, fn (CarbonImmutable $today): int => $this->app->make(PruneExpiredCarts::class)->handle());
    }
}
