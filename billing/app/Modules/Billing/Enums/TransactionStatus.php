<?php

namespace App\Modules\Billing\Enums;

enum TransactionStatus: string
{
    case Succeeded = 'succeeded';
    case Pending = 'pending';
    case Failed = 'failed';
}
