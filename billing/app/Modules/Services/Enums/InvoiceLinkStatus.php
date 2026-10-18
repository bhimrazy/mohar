<?php

namespace App\Modules\Services\Enums;

/**
 * Local mirror of whether an invoice that bills a service period is still open.
 */
enum InvoiceLinkStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
