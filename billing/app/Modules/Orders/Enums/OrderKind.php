<?php

namespace App\Modules\Orders\Enums;

enum OrderKind: string
{
    case New = 'new';
    case Upgrade = 'upgrade';
    case Addon = 'addon';
    case RenewalManual = 'renewal_manual';
}
