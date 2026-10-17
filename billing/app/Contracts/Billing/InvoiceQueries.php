<?php

namespace App\Contracts\Billing;

/**
 * Read-only invoice facts for other modules. Implemented by Billing; Core binds a null default.
 */
interface InvoiceQueries
{
    /**
     * Whether any payment, credit application or refund has been recorded against the invoice.
     */
    public function hasPayments(int $invoiceId): bool;

    /**
     * Whether any invoice, quote or transaction exists in the currency (used to lock the default currency).
     */
    public function hasDocumentsInCurrency(string $currency): bool;
}
