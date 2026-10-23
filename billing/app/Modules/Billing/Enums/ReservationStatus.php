<?php

namespace App\Modules\Billing\Enums;

/**
 * Refund reservation states (ADR 0024 §5): pending until the provider answers, then finalised into a note or released.
 */
enum ReservationStatus: string
{
    case Pending = 'pending';
    case Finalised = 'finalised';
    case Released = 'released';
}
