<?php

namespace App\Modules\Orders\Actions;

use App\Contracts\Billing\Invoicer;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Delete an order that never took money: refused once the invoice has been paid (or is refunded/in
 * collections). Pending items and the open invoice are cancelled, promotion uses released.
 */
class DeleteOrder
{
    private const DELETABLE_INVOICE_STATUSES = [null, 'draft', 'unpaid', 'cancelled'];

    public function __construct(
        private ReleasePendingItems $items,
        private ReleasePromotionRedemptions $promotions,
        private Invoicer $invoicer,
        private ActivityLogger $activity,
    ) {}

    public function handle(Order $order): void
    {
        $invoiceStatus = $order->invoice_id === null ? null : $this->invoicer->status($order->invoice_id);

        if (! in_array($invoiceStatus, self::DELETABLE_INVOICE_STATUSES, true)) {
            throw new DomainException(__('This order has payments on its invoice and cannot be deleted. Cancel it instead.'));
        }

        DB::transaction(function () use ($order): void {
            $this->items->handle($order, OrderItemStatus::Cancelled, [OrderItemStatus::Pending]);
            $this->promotions->handle($order);

            if ($order->invoice_id !== null) {
                $this->invoicer->cancel($order->invoice_id);
            }

            $order->delete();
        });

        $this->activity->log("Order {$order->number} deleted", null, $order->client_id, ['order_id' => $order->id]);
    }
}
