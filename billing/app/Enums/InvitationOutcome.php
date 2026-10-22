<?php

namespace App\Enums;

/**
 * What ClientLoginInvitations::invite() did with one login.
 */
enum InvitationOutcome: string
{
    /** A new single-use link was mailed (or queued) to the login's own address. */
    case Sent = 'sent';

    /** The login cannot be invited: it has a password, a sign-in or two-factor authentication, it is a staff login,
     * it no longer has access to the client, or the client is closed. Nothing was sent. */
    case Skipped = 'skipped';

    /** Nothing was sent because a link may not be mailed now (the link host check failed, or the template system
     * refused the link). Nothing may send it by another path. */
    case Refused = 'refused';

    /** No module sends invitations in this install. */
    case Unavailable = 'unavailable';
}
