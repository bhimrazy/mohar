<?php

namespace App\Modules\Billing\Enums;

/**
 * A credit note lowers the value of the supply, a debit note raises it (ADR 0024 §1).
 */
enum TaxNoteKind: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    /** The signed effect on the value of the invoice. */
    public function sign(): int
    {
        return $this === self::Debit ? 1 : -1;
    }
}
