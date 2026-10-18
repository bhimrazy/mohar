<?php

namespace App\Modules\Billing\Enums;

enum TaxExemptReason: string
{
    case Exempt = 'exempt';
    case ReverseCharge = 'reverse_charge';
}
