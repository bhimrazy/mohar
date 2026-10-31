<?php

namespace App\Modules\Orders\Boot;

use App\Support\Permissions\PermissionRegistry;
use Illuminate\Contracts\Container\Container;

final class Permissions
{
    public function __invoke(Container $app): void
    {
        $app->make(PermissionRegistry::class)->register('Orders', [
            'orders.orders.view' => 'View orders',
            'orders.orders.create' => 'Place orders for clients',
            'orders.orders.accept' => 'Accept orders',
            'orders.orders.cancel' => 'Cancel orders',
            'orders.orders.mark_fraud' => 'Mark orders as fraud, set back to pending, re-run fraud checks',
            'orders.orders.delete' => 'Delete orders',
            'orders.promotions.manage' => 'Manage promotions',
            'orders.fraud.configure' => 'Configure order and fraud settings',
        ]);
    }
}
