<?php

namespace App\Modules\Billing\Enums;

enum InvoiceKind: string
{
    case Standard = 'standard';
    case AddFunds = 'add_funds';
}
