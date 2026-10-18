<?php

namespace App\Enums;

/**
 * Whether a command whose outcome was unknown ran at the provider. Shared by provisioning (CommandLookup) and
 * registrars (SubmissionLookup): one vocabulary, family-specific results.
 *
 * Closed enum (ADR 0010): the verify sweep branches on every case.
 */
enum ReconcileStatus: string
{
    /** The command took effect. */
    case Executed = 'executed';

    /** The provider accepted it and is still working on it; ask again later. */
    case InProgress = 'in_progress';

    /** The command did not take effect; staff may run it again. */
    case NotExecuted = 'not_executed';

    /** Something is there that the command did not make (another owner's account or domain). Staff must decide. */
    case Conflict = 'conflict';

    /** The lookup itself failed; nothing is known. */
    case Unknown = 'unknown';
}
