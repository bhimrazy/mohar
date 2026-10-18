<?php

namespace App\Modules\Notifications\Enums;

/**
 * pending: waiting for its first attempt · failed: last attempt failed, a retry is scheduled ·
 * succeeded: 2xx received · abandoned: max attempts reached (spec §8).
 */
enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
