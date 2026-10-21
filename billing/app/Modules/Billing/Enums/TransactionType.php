<?php

namespace App\Modules\Billing\Enums;

enum TransactionType: string
{
    case Payment = 'payment';
    case Refund = 'refund';
    case Chargeback = 'chargeback';
    case ChargebackReversal = 'chargeback_reversal';
    /** A dispute opened without money withdrawn (an inquiry): amounts stay 0, it only carries the staff to-do. */
    case DisputeInquiry = 'dispute_inquiry';
    case ManualIncome = 'manual_income';
    case ManualExpense = 'manual_expense';

    public function isIncome(): bool
    {
        return in_array($this, [self::Payment, self::ManualIncome, self::ChargebackReversal], true);
    }
}
