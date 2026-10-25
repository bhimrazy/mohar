<?php

namespace App\Modules\Billing\Enums;

enum AdjustmentReason: string
{
    case Cancellation = 'cancellation';
    case ItemCancellation = 'item_cancellation';
    case CreditApplied = 'credit_applied';
    case CreditRemoved = 'credit_removed';
    case Refund = 'refund';
    case LateFee = 'late_fee';
    case Overpayment = 'overpayment';
    case WriteOff = 'write_off';
    case Chargeback = 'chargeback';
    case Manual = 'manual';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
    /** Settlement of a credit note above the balance to account credit, or (credit kind) its take-back by a reversal. */
    case CreditNoteToCredit = 'credit_note_to_credit';
}
