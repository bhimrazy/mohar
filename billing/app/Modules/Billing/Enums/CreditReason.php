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
    case CreditNote = 'credit_note';
    case CreditNoteReversed = 'credit_note_reversed';
    /** Account credit paid out to the client (ADR 0024 §5a); acts as the hold while the provider's refund is pending. */
    case CreditRefunded = 'credit_refunded';
    /** A credit refund the provider did not make: the payout is reversed. */
    case CreditRefundReleased = 'credit_refund_released';
}
