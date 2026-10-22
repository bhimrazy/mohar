<?php

namespace App\Enums;

/**
 * What the provider answered when asked to close a hosted payment session (CancelsHostedPayment). Recorded in the
 * gateway log; it never decides settlement, which comes from lookup() only.
 *
 * Closed enum (ADR 0010).
 */
enum HostedSessionCancel: string
{
    /** The provider closed the session: it can no longer be paid. */
    case Closed = 'closed';

    /** The session was already paid, expired or closed, so there was nothing to close. */
    case AlreadyFinal = 'already_final';

    /** No usable answer (network error, timeout, provider error); nothing is known. */
    case Unreachable = 'unreachable';
}
