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

    /** A numbered tax document: the figures stay as filed, a mistake is corrected by a note against the invoice. */
    public static function numbered(): self
    {
        return new self(__('A numbered tax document cannot be changed or deleted. Correct it with a credit or debit note.'));
    }
}
