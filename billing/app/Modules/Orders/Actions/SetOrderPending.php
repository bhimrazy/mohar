<?php

namespace App\Modules\Orders\Actions;

use App\Contracts\Billing\Invoicer;
use App\Data\Billing\InvoiceDraft;
use App\Events\Orders\OrderSetPending;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Pricing\OrderInvoiceLines;
use App\Modules\Orders\Services\CompanyClock;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Fraud → pending (staff cleared the review). The order and its flagged items become pending again and the
 * PaymentGuard lets the invoice be paid. When staff had cancelled the invoice while marking fraud, the
 * same lines are re-issued on a new invoice due today. Promotion uses were never released while flagged,
 * so usage stays consistent with max_uses.
 */
class SetOrderPending
{
    public function __construct(
        private Invoicer $invoicer,
        private OrderInvoiceLines $lines,
        private CompanyClock $clock,
        private ActivityLogger $activity,
    ) {}

    public function handle(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            if (! $order->transition([OrderStatus::Fraud], OrderStatus::Pending)) {
                throw new DomainException(__('Only orders marked as fraud can be set back to pending.'));
            }

            $order->items()->where('status', OrderItemStatus::Fraud)->update(['status' => OrderItemStatus::Pending]);

            if ($order->invoice_id !== null && $this->invoicer->status($order->invoice_id) === 'cancelled') {
                $this->reissueInvoice($order);
            }
        });

        $this->activity->log("Order {$order->number} set back to pending", $order, $order->client_id);
        OrderSetPending::dispatch($order->id, $order->client_id);
    }

    private function reissueInvoice(Order $order): void
    {
        $today = $this->clock->today();
        $invoice = $this->invoicer->issue(new InvoiceDraft(
            clientId: $order->client_id,
            currency: $order->currency,
            lines: $this->lines->build($order, $today),
            dueDate: $today,
            issueDate: $today,
            paymentGateway: $order->payment_gateway,
            notes: __('Order :number', ['number' => $order->number]),
        ));

        $order->forceFill(['invoice_id' => $invoice->invoiceId, 'tax_total' => $invoice->tax, 'total' => $invoice->total])->save();
    }
}
