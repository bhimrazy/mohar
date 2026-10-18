<?php

namespace App\Modules\Orders\Listeners;

use App\Events\Billing\InvoicePaid;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Actions\AcceptOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use DomainException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * orders.auto_accept_on_payment: a pending order whose invoice is paid and whose fraud check passed (or
 * was skipped) becomes active. Checkout screens the order before issuing the invoice, and this listener
 * runs after commit, so a zero-total invoice paid on issue already finds the decision.
 */
class AcceptPaidOrder implements ShouldHandleEventsAfterCommit
{
    /**
     * AcceptOrder (and the Services/Domains registries behind it) is resolved only when an order invoice
     * is to be accepted: other modules pay invoices without Orders' dependencies being bound.
     */
    public function __construct(
        private Container $container,
        private Settings $settings,
    ) {}

    public function handle(InvoicePaid $event): void
    {
        if (! $this->settings->get('orders.auto_accept_on_payment', false)) {
            return;
        }

        $order = Order::query()->where('invoice_id', $event->invoiceId)->with('items')->first();

        if ($order === null || $order->status !== OrderStatus::Pending || ! $order->fraud_decision?->allowsAutoProvisioning()) {
            return;
        }

        try {
            $this->container->make(AcceptOrder::class)->handle($order, invoiceStatus: 'paid');
        } catch (DomainException) {
            // Accepted concurrently (staff or another payment event) - nothing left to do.
        }
    }
}
