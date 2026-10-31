<?php

namespace App\Modules\Orders\Boot;

use App\Models\User;
use App\Modules\Core\Support\RequestMemo;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Navigation\Navigation as NavigationMenu;
use App\Support\Navigation\NavItem;
use Illuminate\Contracts\Container\Container;

final class Navigation
{
    public function __invoke(Container $app): void
    {
        $navigation = $app->make(NavigationMenu::class);
        $navigation->add('admin', new NavItem('Orders', 'admin.orders.index', 'ShoppingCart', 'Orders', 10, 'orders.orders.view', 'admin.orders.*',
            badge: fn (?User $user): ?int => RequestMemo::remember('orders.pending_count', fn (): int => Order::query()->where('status', OrderStatus::Pending)->count()) ?: null));
        $navigation->add('admin-create', new NavItem('New order', 'admin.orders.create', 'ShoppingCart', order: 20, permission: 'orders.orders.create'));
        $navigation->add('admin', new NavItem('Promotions', 'admin.promotions.index', 'Percent', 'Orders', 20, 'orders.promotions.manage', 'admin.promotions.*'));
        $navigation->add('client', new NavItem('Order history', 'client.orders.index', 'Receipt', 'Orders', 10, null, 'client.orders.*'));
        // Every store page except the domain search, which "Register a domain" (Domains) marks.
        $navigation->add('client', new NavItem('Store', 'store.index', 'ShoppingCart', 'Orders', 20, null, 'store.[!d]*'));
        $navigation->add('admin-client', new NavItem('Orders', 'admin.orders.index', 'ShoppingCart', order: 20, permission: 'orders.orders.view'));
    }
}
