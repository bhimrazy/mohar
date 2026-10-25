<?php

namespace App\Modules\Orders\Actions;

use App\Contracts\Billing\Invoicer;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Cancels pending orders whose invoice is still unpaid N days after ordering
 * (orders.auto_cancel_unpaid_days, 0 = off). Idempotent: cancelled orders are no longer pending.
 */
class CancelStalePendingOrders
{
    public function __construct(
        private CancelOrder $cancel,
        private Invoicer $invoicer,
        private Settings $settings,
    ) {}

    public function handle(CarbonImmutable $today): int
    {
        $days = (int) $this->settings->get('orders.auto_cancel_unpaid_days', 0);

        if ($days <= 0) {
            return 0;
        }

        $cancelled = 0;

        Order::query()
            ->where('status', OrderStatus::Pending)
            ->whereNotNull('invoice_id')
            ->where('created_at', '<', $today->subDays($days - 1)->startOfDay())
            ->eachById(function (Order $order) use (&$cancelled): void {
                if ($this->invoicer->status((int) $order->invoice_id) === 'unpaid') {
                    $this->cancel->handle($order, notify: false);
                    $cancelled++;
                }
            });

        return $cancelled;
    }
}
