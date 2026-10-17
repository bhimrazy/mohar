<?php

namespace App\Modules\Services\Enums;

/**
 * How a server group picks the server for a new account.
 */
enum FillType: string
{
    /** Lowest active_accounts / max_accounts ratio first (unlimited servers count as 0 % full). */
    case LeastFull = 'least_full';

    /** First server in group order that still has free capacity. */
    case FillSequentially = 'fill_sequentially';

    public function label(): string
    {
        return match ($this) {
            self::LeastFull => 'Least full',
            self::FillSequentially => 'Fill until full',
        };
    }
}
