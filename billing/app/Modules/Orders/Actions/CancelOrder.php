<?php

namespace App\Modules\Orders\Actions;

use App\Contracts\Billing\InvoiceQueries;
use App\Contracts\Billing\Invoicer;
use App\Events\Orders\OrderCancelled;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Order → cancelled: items that never went live are cancelled, the unpaid invoice is cancelled (Billing
 * no-ops a paid one) and the promotion use is released when nothing was paid. Services terminates
 * activated items and Catalog returns stock, both reacting to OrderCancelled.
 */
class CancelOrder
{
    public function __construct(
        private ReleasePendingItems $items,
        private ReleasePromotionRedemptions $promotions,
        private Invoicer $invoicer,
        private InvoiceQueries $invoices,
        private ActivityLogger $activity,
    ) {}

    /**
     * @param  bool  $notifyClient  false when the system cancels on its own (a stale unpaid order): the credit note is not emailed
     */
    public function handle(Order $order, bool $notifyClient = true): void
    {
        DB::transaction(function () use ($order, $notifyClient): void {
            if (! $order->transition([OrderStatus::Pending, OrderStatus::Active, OrderStatus::Fraud], OrderStatus::Cancelled, ['cancelled_at' => now()])) {
                throw new DomainException(__('This order is already cancelled.'));
            }

            $this->items->handle($order);

            if ($order->invoice_id !== null) {
                if (! $this->invoices->hasPayments($order->invoice_id)) {
                    $this->promotions->handle($order);
                }

                $this->invoicer->cancel($order->invoice_id, __('Cancelled by :module', ['module' => 'Orders']), $notifyClient);
            } else {
                $this->promotions->handle($order);
            }
        });

        $this->activity->log("Order {$order->number} cancelled", $order, $order->client_id);
        OrderCancelled::dispatch($order->id, $order->client_id);
    }
}
