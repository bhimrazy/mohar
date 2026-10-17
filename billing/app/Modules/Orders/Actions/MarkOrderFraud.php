<?php

namespace App\Modules\Orders\Actions;

use App\Contracts\Billing\Invoicer;
use App\Events\Orders\OrderMarkedFraud;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Order and items → fraud. A failed checkout screen keeps the invoice unpaid (payments are refused by
 * OrderPaymentGuard while flagged); staff "Mark fraud" also cancels the invoice.
 */
class MarkOrderFraud
{
    public function __construct(
        private ReleasePendingItems $items,
        private ReleasePromotionRedemptions $promotions,
        private Invoicer $invoicer,
        private ActivityLogger $activity,
    ) {}

    public function handle(Order $order, bool $cancelInvoice = true): void
    {
        if ($order->status === OrderStatus::Fraud || $order->status === OrderStatus::Cancelled) {
            throw new DomainException(__('Only pending or active orders can be marked as fraud.'));
        }

        DB::transaction(function () use ($order, $cancelInvoice): void {
            $paid = $order->invoice_id !== null && $this->invoicer->status($order->invoice_id) === 'paid';

            $order->update(['status' => OrderStatus::Fraud]);
            $this->items->handle($order, OrderItemStatus::Fraud);

            if (! $paid) {
                $this->promotions->handle($order);
            }

            if ($cancelInvoice && $order->invoice_id !== null) {
                $this->invoicer->cancel($order->invoice_id);
            }
        });

        $this->activity->log("Order {$order->number} marked as fraud", $order, $order->client_id);
        OrderMarkedFraud::dispatch($order->id, $order->client_id);
    }
}
