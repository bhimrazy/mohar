<?php

namespace App\Enums;

/**
 * What the template system did with a SystemMailer request.
 */
enum SystemMailOutcome: string
{
    /** Queued, or suppressed on purpose (mail switched off, demo data): nothing else may send it. */
    case Taken = 'taken';

    /** The template system refused it (a one-time link failed its checks, the template failed to render). Nothing
     * may send it by another path; the caller tells the person who acted. */
    case Refused = 'refused';

    /** No template system or no installed template: the caller may send its own fallback. */
    case Unavailable = 'unavailable';
}
