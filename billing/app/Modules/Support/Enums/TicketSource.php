<?php

namespace App\Modules\Support\Enums;

enum TicketSource: string
{
    case Web = 'web';
    case Email = 'email';
    case Staff = 'staff';
    case Api = 'api';
}
