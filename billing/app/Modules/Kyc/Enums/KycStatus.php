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
    case Rejected = 'rejected';
    case Verified = 'verified';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::PendingReview => 'Pending review',
            self::Rejected => 'Rejected',
            self::Verified => 'Verified',
        };
    }
}
