<?php

namespace App\Modules\Orders\Boot;

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Contracts\Container\Container;

final class MergeFields
{
    public function __invoke(Container $app): void
    {
        $app->make(MergeFieldRegistry::class)->register('order', function (int $id): array {
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
}
