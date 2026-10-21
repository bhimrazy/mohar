<?php

namespace App\Modules\Kyc\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
