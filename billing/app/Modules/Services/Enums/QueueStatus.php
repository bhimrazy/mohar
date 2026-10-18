<?php

namespace App\Modules\Services\Enums;

/**
 * Status of a failed module command awaiting staff attention (module_queue).
 */
enum QueueStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
