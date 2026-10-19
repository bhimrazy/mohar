<?php

namespace App\Modules\Notifications\Enums;

/**
 * Lifecycle of an email_messages row (spec §2).
 */
enum EmailStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Suppressed = 'suppressed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
