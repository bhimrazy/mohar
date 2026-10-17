<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Fraud → pending (staff review cleared): the order and its fraud items become pending again, which
 * re-enables payment of the invoice.
 */
class SetOrderPending
{
    public function __construct(private ActivityLogger $activity) {}

    public function handle(Order $order): void
    {
        if ($order->status !== OrderStatus::Fraud) {
            throw new DomainException(__('Only orders marked as fraud can be set back to pending.'));
        }

        DB::transaction(function () use ($order): void {
            $order->update(['status' => OrderStatus::Pending]);
            $order->items()->where('status', OrderItemStatus::Fraud)->update(['status' => OrderItemStatus::Pending]);
        });

        $this->activity->log("Order {$order->number} set back to pending", $order, $order->client_id);
    }
}
