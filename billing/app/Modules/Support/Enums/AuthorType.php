<?php

namespace App\Modules\Support\Enums;

enum AuthorType: string
{
    case Client = 'client';
    case Guest = 'guest';
    case Staff = 'staff';
    case System = 'system';

    public function isCustomer(): bool
    {
        return $this === self::Client || $this === self::Guest;
    }
}
