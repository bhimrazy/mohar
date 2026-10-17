<?php

namespace App\Modules\Orders\Services;

use App\Contracts\Orders\PaymentGuard;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;

/**
 * Billing consults this before accepting a payment (ADR 0008). Payments are refused while the invoice's
 * order is flagged as fraud (orders.md §7) or waits for a manual fraud review. Provider errors that fail
 * open stay payable.
 */
class OrderPaymentGuard implements PaymentGuard
{
    public function refusalReason(int $invoiceId): ?string
    {
        $order = Order::query()->where('invoice_id', $invoiceId)->first(['id', 'status', 'fraud_decision']);

        if ($order === null) {
            return null;
        }

        if ($order->status === OrderStatus::Fraud) {
            return __('This order is under review. Payment will be possible once the review is complete.');
        }

        if ($order->status === OrderStatus::Pending && $order->fraud_decision === FraudDecision::Review) {
            return __('This order is waiting for a security review. You can pay once it has been approved.');
        }

        return null;
    }
}
