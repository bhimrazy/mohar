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
}
