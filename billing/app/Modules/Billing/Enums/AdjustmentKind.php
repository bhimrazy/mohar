<?php

namespace App\Modules\Billing\Enums;

/**
 * Credit notes reduce the balance, debit notes increase it.
 */
enum AdjustmentKind: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    /**
     * Sign of the adjustment in the balance formula (balance = total + Σdebit − Σcredit − amount_paid).
     */
    public function sign(): int
    {
        return $this === self::Debit ? 1 : -1;
    }
}
