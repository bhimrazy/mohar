<?php

namespace App\Modules\Orders\Boot;

use App\Modules\Orders\Models\Order;
use App\Support\Clients\ClientCurrencyUsage;
use Illuminate\Contracts\Container\Container;

final class CurrencyUsage
{
    public function __invoke(Container $app): void
    {
        // An order's amounts are stored in its client's currency (client-import spec §6.4).
        $app->make(ClientCurrencyUsage::class)->register('orders', fn (array $clientIds): array => Order::query()
            ->whereIn('client_id', $clientIds)->distinct()->pluck('client_id')->all());
    }
}
