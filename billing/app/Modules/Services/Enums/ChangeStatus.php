<?php

namespace App\Modules\Services\Enums;

/**
 * Status of an upgrade/downgrade (service_changes).
 */
enum ChangeStatus: string
{
    case PendingPayment = 'pending_payment';
    case Applied = 'applied';
    case ApplyFailed = 'apply_failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
