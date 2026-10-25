<?php

namespace App\Modules\Orders\Actions;

use App\Contracts\Billing\Invoicer;
use App\Events\Orders\OrderMarkedFraud;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Billing\InvoiceNotCancellable;
use DomainException;

/**
 * Order and items → fraud. Flagging is reversible (SetOrderPending), so nothing is released here: the
 * promotion use stays counted, pending services/domains stay pending (provisioning skips fraud orders) and
 * payments are refused by the PaymentGuard. Stock is returned by Catalog reacting to OrderMarkedFraud.
 * A staff "Mark fraud" also cancels the invoice (orders.md §7); a failed checkout screen keeps it.
 */
class MarkOrderFraud
{
    public function __construct(
        private Invoicer $invoicer,
        private ActivityLogger $activity,
    ) {}

    /**
     * Staff action: flag, cancel the invoice and notify other modules.
     */
    public function handle(Order $order, bool $cancelInvoice = true): void
    {
        $this->flag($order);

        if ($cancelInvoice && $order->invoice_id !== null) {
            try {
                // A fraud flag is not news for the client: the note is filed, not emailed.
                $this->invoicer->cancel($order->invoice_id, __('Cancelled by :module', ['module' => 'Orders']), notifyClient: false);
            } catch (InvoiceNotCancellable $exception) {
                // The order is flagged all the same; its invoice stays payable and is on Billing's staff list.
                $this->activity->log("Order {$order->number}: its invoice could not be cancelled", $order, $order->client_id, ['reason' => $exception->getMessage()]);
            }
        }

        $this->activity->log("Order {$order->number} marked as fraud", $order, $order->client_id);
        OrderMarkedFraud::dispatch($order->id, $order->client_id);
    }

    /**
     * Flag the order and its pending items without side effects (the checkout uses this inside its
     * transaction and dispatches OrderMarkedFraud after commit).
     */
    public function flag(Order $order): void
    {
        if (! $order->transition([OrderStatus::Pending, OrderStatus::Active], OrderStatus::Fraud)) {
            throw new DomainException(__('Only pending or active orders can be marked as fraud.'));
        }

        // Only items that never went live are flagged; activated services stay active (Services decides
        // whether to suspend them on OrderMarkedFraud) so set-pending + accept can never provision them again.
        $order->items()->where('status', OrderItemStatus::Pending)->update(['status' => OrderItemStatus::Fraud]);
    }
}
