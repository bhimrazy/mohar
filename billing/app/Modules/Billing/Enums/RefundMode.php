<?php

namespace App\Modules\Billing\Enums;

enum RefundMode: string
{
    /** Refund through the gateway driver. */
    case Gateway = 'gateway';

    /** Money was returned outside the system; only record it. */
    case RecordOnly = 'record_only';

    /** Return the amount to the client's credit balance. */
    case ToCredit = 'to_credit';
}
