<?php

namespace App\Modules\Services\Enums;

enum CancellationType: string
{
    case Immediate = 'immediate';
    case EndOfPeriod = 'end_of_period';

    public function label(): string
    {
        return match ($this) {
            self::Immediate => 'Immediately',
            self::EndOfPeriod => 'End of billing period',
        };
    }
}
