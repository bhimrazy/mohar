<?php

namespace App\Contracts\Orders;

/**
 * Consulted by Billing before accepting a payment for an invoice (e.g. the order is flagged as fraud).
 * Core binds a null implementation that always allows.
 */
interface PaymentGuard
{
    /**
     * Null when payment may proceed, otherwise a human-readable reason to refuse it.
     */
    public function refusalReason(int $invoiceId): ?string;
}
