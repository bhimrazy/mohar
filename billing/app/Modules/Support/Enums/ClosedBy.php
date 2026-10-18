<?php

namespace App\Modules\Support\Enums;

enum ClosedBy: string
{
    case Client = 'client';
    case Staff = 'staff';
    case System = 'system';
}
