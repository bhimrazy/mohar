<?php

namespace App\Modules\Services\Enums;

enum CancellationStatus: string
{
    case Open = 'open';
    case Processed = 'processed';
    case Voided = 'voided';
    case Failed = 'failed';
}
