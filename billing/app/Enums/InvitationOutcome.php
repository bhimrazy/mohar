<?php

namespace App\Enums;

/**
 * What ClientLoginInvitations::invite() did with one login.
 *
 * Open enum (ADR 0010): cases may be added in a minor version, so consumers need a default branch.
 *
 * @open
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

    /** Nothing was sent because mail is held on purpose (email switched off, or the invitation template turned off).
     * No link was issued; the login can be invited once mail is on again. */
    case Held = 'held';

    /** No module sends invitations in this install. */
    case Unavailable = 'unavailable';
}
