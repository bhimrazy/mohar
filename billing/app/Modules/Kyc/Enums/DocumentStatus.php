<?php

namespace App\Modules\Kyc\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for review',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
        };
    }
}
