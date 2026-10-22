<?php

namespace App\Enums;

/**
 * What the template system did with a SystemMailer request.
 */
enum SystemMailOutcome: string
{
    /** Queued to be sent: nothing else may send it. */
    case Taken = 'taken';

    /** Logged but not sent, on purpose (mail switched off, the template turned off, demo data). Nothing else may send
     * it; a caller that records the mail as sent (a one-time link) puts that record back. */
    case Suppressed = 'suppressed';

    /** The template system refused it (a one-time link failed its checks, the template failed to render). Nothing
     * may send it by another path; the caller tells the person who acted. */
    case Refused = 'refused';

    /** No template system or no installed template: the caller may send its own fallback. */
    case Unavailable = 'unavailable';
}
