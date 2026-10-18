<?php

namespace App\Modules\Billing\Enums;

/**
 * Lifecycle of a hosted payment attempt. Only an authoritative lookup moves it to succeeded.
 */
enum AttemptStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';

    /** Provider can't say yet (e.g. eSewa AMBIGUOUS) or a guard failed: keep polling, staff review, never auto-fail. */
    case Ambiguous = 'ambiguous';

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Ambiguous;
    }
}
