<?php

namespace App\Modules\Orders\Boot;

use App\Modules\Core\Support\Like;
use App\Modules\Orders\Models\Order;
use App\Support\Search\GlobalSearch;
use Illuminate\Contracts\Container\Container;

final class Search
{
    public function __invoke(Container $app): void
    {
        $app->make(GlobalSearch::class)->provider('Orders', fn (string $term): array => array_values(Order::query()
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
    }
}
