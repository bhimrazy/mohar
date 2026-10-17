<?php

namespace App\Modules\Orders\Listeners;

use App\Events\Billing\InvoicePaid;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Actions\AcceptOrder;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * orders.auto_accept_on_payment: a pending order whose invoice is paid and whose fraud check passed
 * becomes active. Runs after commit so a zero-total checkout (paid inside the order transaction) sees
 * the order's invoice id.
 */
class AcceptPaidOrder implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private AcceptOrder $accept,
        private Settings $settings,
    ) {}

    public function handle(InvoicePaid $event): void
    {
        if (! $this->settings->get('orders.auto_accept_on_payment', false)) {
            return;
        }

        $order = Order::query()->where('invoice_id', $event->invoiceId)->with('items')->first();

        if ($order === null || $order->status !== OrderStatus::Pending || $order->fraud_decision !== FraudDecision::Pass) {
            return;
        }

        $this->accept->handle($order, invoiceStatus: 'paid');
    }
}
