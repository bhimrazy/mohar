<?php

namespace App\Modules\Notifications\Enums;

enum TemplateAudience: string
{
    case Client = 'client';
    case Staff = 'staff';
}
