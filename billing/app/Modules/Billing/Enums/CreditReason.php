<?php

namespace App\Modules\Billing\Enums;

enum CreditReason: string
{
    case Overpayment = 'overpayment';
    case AddFunds = 'add_funds';
    case RefundToCredit = 'refund_to_credit';
    case AppliedToInvoice = 'applied_to_invoice';
    case CreditRemoved = 'credit_removed';
    case Manual = 'manual';
    case Downgrade = 'downgrade';
    case Reversal = 'reversal';
    /** An opening balance carried over by a client import, a correction from a later run, or its undo. */
    case Import = 'import';
}
