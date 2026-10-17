<?php

namespace App\Modules\Orders\Actions;

use App\Contracts\Billing\Invoicer;
use App\Events\Orders\OrderCancelled;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Order → cancelled: pending items cancelled, the unpaid invoice cancelled (Billing no-ops a paid one),
 * the promotion use released when nothing was paid. Activated items are terminated by Services
 * reacting to OrderCancelled.
 */
class CancelOrder
{
    public function __construct(
        private ReleasePendingItems $items,
        private ReleasePromotionRedemptions $promotions,
        private Invoicer $invoicer,
        private ActivityLogger $activity,
    ) {}

    public function handle(Order $order): void
    {
        if ($order->status === OrderStatus::Cancelled) {
            throw new DomainException(__('This order is already cancelled.'));
        }

        DB::transaction(function () use ($order): void {
            $paid = $order->invoice_id !== null && $this->invoicer->status($order->invoice_id) === 'paid';

            $order->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);
            $this->items->handle($order, OrderItemStatus::Cancelled, [OrderItemStatus::Pending, OrderItemStatus::Active, OrderItemStatus::Fraud]);

            if ($order->invoice_id !== null) {
                $this->invoicer->cancel($order->invoice_id);
            }

            if (! $paid) {
                $this->promotions->handle($order);
            }
        });

        $this->activity->log("Order {$order->number} cancelled", $order, $order->client_id);
        OrderCancelled::dispatch($order->id, $order->client_id);
    }
}
