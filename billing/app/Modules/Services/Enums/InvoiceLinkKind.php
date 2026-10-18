<?php

namespace App\Modules\Services\Enums;

enum InvoiceLinkKind: string
{
    case Order = 'order';
    case Renewal = 'renewal';
}
