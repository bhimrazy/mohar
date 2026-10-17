<?php

namespace App\Modules\Orders\Actions;

use App\Contracts\Billing\InvoiceQueries;
use App\Contracts\Billing\Invoicer;
use App\Events\Orders\OrderCancelled;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Orders\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Delete an order that never took money (orders.md §8): refused once Billing recorded any payment, credit
 * or refund on its invoice. Items are cancelled, the invoice cancelled and promotion uses released;
 * OrderCancelled is dispatched before the row goes so Catalog returns stock and Services cleans up.
 */
class DeleteOrder
{
    public function __construct(
        private ReleasePendingItems $items,
        private ReleasePromotionRedemptions $promotions,
        private Invoicer $invoicer,
        private InvoiceQueries $invoices,
        private ActivityLogger $activity,
    ) {}

    public function handle(Order $order): void
    {
        if ($order->invoice_id !== null && $this->invoices->hasPayments($order->invoice_id)) {
            throw new DomainException(__('This order has payments on its invoice and cannot be deleted. Cancel it instead.'));
        }

        DB::transaction(function () use ($order): void {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw new DomainException(__('This order no longer exists.'));
            }

            $this->items->handle($locked);
            $this->promotions->handle($locked);

            if ($locked->invoice_id !== null) {
                $this->invoicer->cancel($locked->invoice_id);
            }

            $locked->delete();
        });

        OrderCancelled::dispatch($order->id, $order->client_id);
        $this->activity->log("Order {$order->number} deleted", null, $order->client_id, ['order_id' => $order->id]);
    }
}
