<?php

namespace App\Modules\Orders;

use App\Contracts\Billing\GatewayDirectory;
use App\Contracts\Billing\Invoicer;
use App\Contracts\Billing\TaxCalculator;
use App\Contracts\Notifications\MergeFieldRegistry;
use App\Contracts\Orders\PaymentGuard;
use App\Contracts\Services\ServiceRegistry;
use App\Events\Billing\InvoicePaid;
use App\Events\Domains\DomainRegistered;
use App\Events\Domains\DomainTransferred;
use App\Events\Services\ServiceActivated;
use App\Models\User;
use App\Modules\Core\Support\Like;
use App\Modules\Core\Support\Settings;
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
use App\Modules\Orders\Services\OrderPaymentGuard;
use App\Modules\Orders\Support\Demo\DemoBilling;
use App\Modules\Orders\Support\Demo\DemoServices;
use App\Support\Automation\Automation;
use App\Support\Dashboard\Dashboard;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Money;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

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

        $this->registerRateLimiters();
        $this->registerNavigation();
        $this->registerPermissionsAndSettings();
        $this->registerSearchAndMergeFields();
        $this->registerAutomation();
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

    /**
     * One limiter per storefront action so the live price preview never eats the checkout budget.
     * Keyed by user when signed in, else by IP (sessions are free to discard, so they cannot be the key);
     * promotion attempts are strict to stop code enumeration.
     */
    private function registerRateLimiters(): void
    {
        $key = fn (Request $request): string => self::throttleKey($request);

        RateLimiter::for('orders-quote', fn (Request $request): Limit => Limit::perMinute(120)->by('quote|'.$key($request)));
        RateLimiter::for('orders-cart', fn (Request $request): Limit => Limit::perMinute(60)->by('cart|'.$key($request)));
        RateLimiter::for('orders-promo', fn (Request $request): Limit => Limit::perMinute(10)->by('promo|'.$key($request)));
        RateLimiter::for('orders-checkout', fn (Request $request): Limit => Limit::perMinute(10)->by('checkout|'.$key($request)));
    }

    public static function throttleKey(Request $request): string
    {
        return $request->user() !== null ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip();
    }

    private function registerNavigation(): void
    {
        $navigation = $this->app->make(Navigation::class);
        $navigation->add('admin', new NavItem('Orders', 'admin.orders.index', 'ShoppingCart', 'Orders', 10, 'orders.orders.view', 'admin.orders.*',
            badge: fn (?User $user): ?int => Order::query()->where('status', OrderStatus::Pending)->count() ?: null));
        $navigation->add('admin-create', new NavItem('New order', 'admin.orders.create', 'ShoppingCart', order: 20, permission: 'orders.orders.create'));
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

        $dashboard->widget('admin', 'orders.recent', 'list', fn (?User $user): array => [
            'title' => 'Recent orders',
            'href' => route('admin.orders.index'),
            'empty' => 'No orders yet.',
            'items' => Order::query()->with('client')->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get()
                ->map(fn (Order $order): array => [
                    'title' => "#{$order->number}",
                    'subtitle' => $order->client->display_name,
                    'href' => route('admin.orders.show', $order),
                    'meta' => Money::of($order->total, $order->currency)->format(),
                    'status' => $order->status->value,
                ])->all(),
        ], 'orders.orders.view', 30, 'md');

        $dashboard->todo('admin', 'orders.pending', fn (?User $user): array => [
            'label' => 'Orders awaiting review',
            'count' => Order::query()->where('status', OrderStatus::Pending)->count(),
            'href' => route('admin.orders.index', ['status' => OrderStatus::Pending->value]),
            'tone' => 'warning',
        ], 'orders.orders.view', 10);
        $dashboard->todo('admin', 'orders.fraud', fn (?User $user): array => [
            'label' => 'Orders flagged as fraud',
            'count' => Order::query()->where('status', OrderStatus::Fraud)->count(),
            'href' => route('admin.orders.index', ['status' => OrderStatus::Fraud->value]),
            'tone' => 'danger',
        ], 'orders.orders.view', 11);
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
            new SettingDefinition('orders.checkout_redirect', 'After checkout, send customers to', 'select', options: ['complete_page' => 'Order confirmation page', 'invoice' => 'Invoice']),
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
            ->where(fn ($query) => Like::where($query, 'number', Like::contains($term)))
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Order $order): array => [
                'title' => "Order #{$order->number}",
                'subtitle' => $order->client->display_name.' · '.ucfirst(str_replace('_', ' ', $order->status->value)),
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
        // Resolve the action (and its Billing dependency) only when auto-cancel is switched on.
        $automation->task('orders.cancel_stale_pending', Automation::CANCELLATIONS, fn (CarbonImmutable $today): int => (int) $this->app->make(Settings::class)->get('orders.auto_cancel_unpaid_days', 0) > 0
            ? $this->app->make(CancelStalePendingOrders::class)->handle($today)
            : 0);
        $automation->task('orders.prune_carts', Automation::HOUSEKEEPING, fn (CarbonImmutable $today): int => $this->app->make(PruneExpiredCarts::class)->handle());
    }
}
