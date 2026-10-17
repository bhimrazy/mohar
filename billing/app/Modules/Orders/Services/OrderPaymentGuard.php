<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;

/**
 * Payments are refused while an invoice's order is flagged as fraud (orders.md §7). Billing must ask
 * this before accepting a payment; it becomes a shared contract once the CTO publishes one.
 */
class OrderPaymentGuard
{
    /** Customer-facing message shown instead of the pay button. */
    public const BLOCKED_MESSAGE = 'This order is under review. Payment will be possible once the review is complete.';

    public function allowsPayment(int $invoiceId): bool
    {
        return ! Order::query()
            ->where('invoice_id', $invoiceId)
            ->where('status', OrderStatus::Fraud)
            ->exists();
    }
}
