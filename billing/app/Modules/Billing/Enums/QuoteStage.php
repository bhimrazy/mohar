<?php

namespace App\Modules\Billing\Enums;

enum QuoteStage: string
{
    case Draft = 'draft';
    case Delivered = 'delivered';
    case OnHold = 'on_hold';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';

    /**
     * Stages in which the client may still accept or decline.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Delivered, self::OnHold], true);
    }
}
