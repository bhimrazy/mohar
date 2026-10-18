<?php

namespace App\Modules\Billing\Exceptions;

/**
 * Issued invoices are immutable: lines, amounts, tax, number and currency never change after publishing.
 */
class ImmutableInvoiceException extends BillingException
{
    public static function make(): self
    {
        return new self(__('Issued invoices cannot be edited. Use an adjustment or reissue the invoice.'));
    }
}
