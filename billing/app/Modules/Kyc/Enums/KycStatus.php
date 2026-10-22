<?php

namespace App\Modules\Kyc\Enums;

/**
 * A client's verification status, derived from the steps (spec §4); never stored.
 */
enum KycStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case PendingReview = 'pending_review';
    case Declined = 'declined';
    case Verified = 'verified';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::PendingReview => 'Pending review',
            self::Declined => 'Declined',
            self::Verified => 'Verified',
        };
    }
}
