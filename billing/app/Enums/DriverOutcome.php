<?php

namespace App\Enums;

/**
 * The one outcome vocabulary of driver calls (ADR 0009 §4). Every driver answer maps to exactly one case.
 *
 * Closed enum (ADR 0010): hosts branch on every case.
 */
enum DriverOutcome: string
{
    /** Done. */
    case Completed = 'completed';

    /** Accepted; finishes asynchronously and the host checks back later. */
    case Pending = 'pending';

    /** Nothing was executed and retrying will not help (bad input, refused by the provider). */
    case Rejected = 'rejected';

    /** Provably not executed (connection refused, provider busy); safe to retry. */
    case Retryable = 'retryable';

    /** May have executed (timeout after sending, unreadable answer). Never retried automatically: staff verify. */
    case Unknown = 'unknown';
}
